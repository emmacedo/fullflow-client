<?php

namespace Kicol\FullFlow\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Kicol\FullFlow\Events\AbstractWebhookEvent;
use Kicol\FullFlow\Events\SubscriptionActivated;
use Kicol\FullFlow\Events\SubscriptionCancellationScheduled;
use Kicol\FullFlow\Events\SubscriptionEnded;
use Kicol\FullFlow\Events\SubscriptionPastDue;
use Kicol\FullFlow\Events\SubscriptionPaymentReceived;
use Kicol\FullFlow\Events\SubscriptionReactivated;
use Kicol\FullFlow\Events\SubscriptionSuspended;
use Kicol\FullFlow\Events\SubscriptionTrialStarted;
use Kicol\FullFlow\Webhook\IdempotencyChecker;
use Kicol\FullFlow\Webhook\SignatureValidator;

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

        $eventId = (string) ($payload['evento_id'] ?? $request->header('X-Fullflow-Event-Id', ''));
        $eventType = (string) ($payload['evento'] ?? '');

        if (!$eventId || !$eventType) {
            return response('Bad Request', 400);
        }

        if ($idempotency->wasProcessed($eventId)) {
            return response('OK (already processed)', 200);
        }

        $eventClass = self::EVENT_MAP[$eventType] ?? null;
        if ($eventClass !== null) {
            event(new $eventClass($payload));
        } else {
            Log::info("FullFlow webhook: tipo de evento desconhecido ignorado: {$eventType}");
        }

        $idempotency->markProcessed($eventId);

        return response('OK', 200);
    }
}
