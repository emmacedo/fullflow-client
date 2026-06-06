<?php

namespace Kicol\FullFlow\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Kicol\FullFlow\Events\AbstractWebhookEvent;
use Kicol\FullFlow\Events\AddonConfirmed;
use Kicol\FullFlow\Events\SubscriptionActivated;
use Kicol\FullFlow\Events\SubscriptionCancellationScheduled;
use Kicol\FullFlow\Events\SubscriptionEnded;
use Kicol\FullFlow\Events\SubscriptionPastDue;
use Kicol\FullFlow\Events\SubscriptionPaymentReceived;
use Kicol\FullFlow\Events\SubscriptionReactivated;
use Kicol\FullFlow\Events\SubscriptionSuspended;
use Kicol\FullFlow\Events\SubscriptionTrialStarted;
use Kicol\FullFlow\Webhook\Handlers\PlanUpdatedHandler;
use Kicol\FullFlow\Webhook\IdempotencyChecker;
use Kicol\FullFlow\Webhook\SignatureValidator;
use Kicol\FullFlow\Webhook\WebhookPayload;

/**
 * Controller base para receber webhooks do FullFlow.
 *
 * Uso recomendado: extender no SaaS e registrar a rota:
 *   Route::post('/webhooks/fullflow', [App\Http\Controllers\FullFlowWebhookController::class, 'handle']);
 *
 * Comportamento:
 *   1. Valida HMAC-SHA256 do body com webhook_secret.
 *   2. Valida timestamp (replay protection).
 *   3. Verifica idempotência (event_id já processado → 200 sem disparar evento).
 *   4. Dispatcha evento Laravel correspondente (SubscriptionActivated, SubscriptionSuspended, etc).
 *   5. Marca como processado.
 *
 * Tudo é loggado em caso de falha.
 */
class FullFlowWebhookController extends Controller
{
    private const EVENT_MAP = [
        'assinatura.trial_iniciado' => SubscriptionTrialStarted::class,
        'assinatura.ativada' => SubscriptionActivated::class,
        'assinatura.past_due' => SubscriptionPastDue::class,
        'assinatura.suspensa' => SubscriptionSuspended::class,
        'assinatura.reativada' => SubscriptionReactivated::class,
        'assinatura.cancelamento_agendado' => SubscriptionCancellationScheduled::class,
        'assinatura.encerrada' => SubscriptionEnded::class,
        'assinatura.pagamento_recebido' => SubscriptionPaymentReceived::class,
        'addon.confirmado' => AddonConfirmed::class,
    ];

    public function __invoke(Request $request, IdempotencyChecker $idempotency): Response
    {
        return $this->handle($request, $idempotency);
    }

    public function handle(Request $request, IdempotencyChecker $idempotency): Response
    {
        $rawBody = $request->getContent();
        $secret = (string) config('fullflow.webhook_secret');

        if ($secret === '') {
            Log::error('FullFlow webhook: webhook_secret não configurado.');
            return response('Server misconfigured', 500);
        }

        $signature = (string) $request->header('X-Fullflow-Signature', '');
        if (!SignatureValidator::isValid($rawBody, $signature, $secret)) {
            Log::warning('FullFlow webhook: assinatura inválida', ['ip' => $request->ip()]);
            return response('Unauthorized', 401);
        }

        $timestamp = (string) $request->header('X-Fullflow-Timestamp', '');
        $tolerance = (int) config('fullflow.replay_protection_minutes', 5);
        if ($timestamp && !SignatureValidator::isTimestampValid($timestamp, $tolerance)) {
            Log::warning('FullFlow webhook: timestamp fora da janela', [
                'timestamp' => $timestamp,
                'tolerance_min' => $tolerance,
            ]);
            return response('Unauthorized', 401);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return response('Bad Request', 400);
        }

        // Envelope dual PT/EN (CL-7, cutover 4.9) — campos obrigatórios
        // continuam obrigatórios em qualquer formato.
        $eventId = WebhookPayload::eventId($payload) ?: (string) $request->header('X-Fullflow-Event-Id', '');
        $eventType = WebhookPayload::eventType($payload);

        if (!$eventId || !$eventType) {
            return response('Bad Request', 400);
        }

        // Validação opt-in de product_code (sync 4.8 passo 3): com a config
        // setada E o payload trazendo o campo, divergência = webhook de outro
        // produto → 400. Eventos legados sem o campo passam (retrocompat).
        $expectedProduct = (string) config('fullflow.product_code', '');
        $incomingProduct = (string) ($payload['product_code'] ?? '');
        if ($expectedProduct !== '' && $incomingProduct !== '' && $incomingProduct !== $expectedProduct) {
            Log::warning('FullFlow webhook: product_code divergente — não é nosso webhook.', [
                'expected' => $expectedProduct,
                'incoming' => $incomingProduct,
            ]);

            return response('Bad Request', 400);
        }

        // Log de auditoria do cutover: a janela de observação da 4.9 exige
        // provar que o formato PT zerou antes de remover o suporte legado.
        Log::info('fullflow.webhook.received', [
            'format' => WebhookPayload::format($payload),
            'event_type' => $eventType,
            'event_id' => $eventId,
        ]);

        // CLAIM com máquina de estados: o evento só vira 'processed' DEPOIS
        // do side effect concluir. Duplicata de evento em processamento
        // recebe 425 (o sender classifica como retry); claim de um worker
        // que morreu (stale) é retomado — crash entre claim e processamento
        // não vira perda silenciosa.
        $claim = $idempotency->claim($eventId, $eventType);

        if ($claim === IdempotencyChecker::DUPLICATE) {
            return response('OK (already processed)', 200);
        }

        if ($claim === IdempotencyChecker::IN_FLIGHT) {
            return response('Processing in flight', 425);
        }

        try {
            if ($eventType === 'plan.updated') {
                // Handler inline (não event Laravel): exception → 500 → outbox
                // do FullFlow retenta; payload inválido → 400 (failed imediato
                // + alerta no FullFlow, sem retry inútil).
                if (! app(PlanUpdatedHandler::class)->handle($payload)) {
                    $idempotency->release($eventId);

                    return response('Unprocessable plan payload', 400);
                }
            } else {
                $eventClass = self::EVENT_MAP[$eventType] ?? null;
                if ($eventClass !== null) {
                    event(new $eventClass($payload));
                } else {
                    Log::info("FullFlow webhook: tipo de evento desconhecido ignorado: {$eventType}");
                }
            }
        } catch (\Throwable $e) {
            // Falha conhecida: reabre imediatamente para a re-entrega.
            $idempotency->release($eventId);

            throw $e;
        }

        $idempotency->markProcessed($eventId, $eventType);

        return response('OK', 200);
    }
}
