<?php

namespace Kicol\FullFlow\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Kicol\FullFlow\Events\SubscriptionActivated;
use Kicol\FullFlow\FullFlowServiceProvider;
use Kicol\FullFlow\Http\Controllers\FullFlowWebhookController;
use Kicol\FullFlow\Models\FullFlowPlan;
use Kicol\FullFlow\Webhook\Handlers\PlanUpdatedHandler;
use Kicol\FullFlow\Webhook\IdempotencyChecker;
use Orchestra\Testbench\TestCase;

/**
 * Receiver fim-a-fim (CL-4 camada 1 + CL-7): HMAC, idempotência durável por
 * event_id (received_webhook_events), envelope PT e EN, roteamento do
 * plan.updated ao handler.
 */
class WebhookReceiverTest extends TestCase
{
    private const SECRET = 'test-webhook-secret';

    protected function getPackageProviders($app): array
    {
        return [FullFlowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('fullflow.webhook_secret', self::SECRET);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();
        IdempotencyChecker::resetTableAvailabilityCache();
        PlanUpdatedHandler::resetFeatureMirrorCache();

        // A rota é responsabilidade do SaaS — registrada aqui como no uso real.
        Route::post('/webhooks/fullflow', FullFlowWebhookController::class);
    }

    private function signedPost(array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/webhooks/fullflow', [], [], [], [
            'HTTP_X-Fullflow-Signature' => hash_hmac('sha256', $body, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_duplicate_event_id_is_noop_and_dispatches_once(): void
    {
        Event::fake([SubscriptionActivated::class]);
        $payload = [
            'evento_id' => '3f43cb5c-4eb3-46cb-9dc0-0b854e33ba01',
            'evento' => 'assinatura.ativada',
            'assinatura_id' => 'uuid-1',
            'dados' => [],
        ];

        $this->signedPost($payload)->assertOk();
        $this->signedPost($payload)->assertOk();

        Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
        $this->assertSame(1, DB::table('received_webhook_events')->where('event_id', '3f43cb5c-4eb3-46cb-9dc0-0b854e33ba01')->count());
    }

    public function test_idempotency_survives_cache_flush_via_durable_table(): void
    {
        Event::fake([SubscriptionActivated::class]);
        $payload = [
            'evento_id' => '3f43cb5c-4eb3-46cb-9dc0-0b854e33ba02',
            'evento' => 'assinatura.ativada',
            'dados' => [],
        ];

        $this->signedPost($payload)->assertOk();
        Cache::flush(); // TTL/flush não pode reabrir a janela de replay
        $this->signedPost($payload)->assertOk();

        Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
    }

    public function test_pt_and_en_envelopes_dispatch_the_same_event(): void
    {
        Event::fake([SubscriptionActivated::class]);

        $this->signedPost([
            'evento_id' => '3f43cb5c-4eb3-46cb-9dc0-0b854e33ba03',
            'evento' => 'assinatura.ativada',
            'referencia_externa' => 'kicol_store_5',
            'dados' => ['plano' => ['code' => 'pro']],
        ])->assertOk();

        $this->signedPost([
            'event_id' => '3f43cb5c-4eb3-46cb-9dc0-0b854e33ba04',
            'event_type' => 'assinatura.ativada',
            'external_reference' => 'kicol_store_5',
            'data' => ['plano' => ['code' => 'pro']],
        ])->assertOk();

        Event::assertDispatchedTimes(SubscriptionActivated::class, 2);
        Event::assertDispatched(SubscriptionActivated::class, function (SubscriptionActivated $e) {
            // Accessors duais leem ambos os envelopes.
            return $e->externalReference() === 'kicol_store_5'
                && $e->data() === ['plano' => ['code' => 'pro']];
        });
    }

    public function test_plan_updated_is_routed_to_handler_and_applied(): void
    {
        $this->signedPost([
            'event_id' => '3f43cb5c-4eb3-46cb-9dc0-0b854e33ba05',
            'event_type' => 'plan.updated',
            'data' => [
                'plan_version' => 1,
                'plan' => ['code' => 'pro', 'name' => 'Pro', 'billing_cycle' => 'mensal', 'amount' => 99],
                'modules' => [['key' => 'cashback']],
                'features' => [],
            ],
        ])->assertOk();

        $plan = FullFlowPlan::where('code', 'pro')->firstOrFail();
        $this->assertSame(1, $plan->plan_version);
        $this->assertSame(['cashback'], $plan->modules()->pluck('slug')->all());
    }

    public function test_invalid_signature_is_rejected_and_nothing_is_marked(): void
    {
        $body = json_encode(['evento_id' => '3f43cb5c-4eb3-46cb-9dc0-0b854e33ba06', 'evento' => 'assinatura.ativada']);

        $this->call('POST', '/webhooks/fullflow', [], [], [], [
            'HTTP_X-Fullflow-Signature' => 'assinatura-errada',
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);

        $this->assertSame(0, DB::table('received_webhook_events')->count());
    }

    public function test_real_uuid_event_id_fits_the_durable_table(): void
    {
        // O produtor real (SubscriptionWebhookOutbox) emite UUID de 36 chars —
        // o CHAR(26) do desenho original (ULID) estourava a coluna.
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $this->assertSame(36, strlen($uuid));

        $this->signedPost([
            'evento_id' => $uuid,
            'evento' => 'assinatura.ativada',
            'dados' => [],
        ])->assertOk();

        $this->assertSame(1, DB::table('received_webhook_events')->where('event_id', $uuid)->count());
    }

    public function test_invalid_plan_payload_returns_400_and_is_not_marked_processed(): void
    {
        // plan.updated sem plan.code: 400 (sender marca failed + alerta) e o
        // event_id NÃO fica queimado — payload corrigido reprocessa depois.
        $broken = [
            'event_id' => '3f43cb5c-4eb3-46cb-9dc0-0b854e33ba07',
            'event_type' => 'plan.updated',
            'data' => ['plan_version' => 1, 'plan' => []],
        ];

        $this->signedPost($broken)->assertStatus(400);
        $this->assertSame(0, DB::table('received_webhook_events')->count());

        // Mesma entrega, payload válido → processa normalmente.
        $fixed = $broken;
        $fixed['data']['plan'] = ['code' => 'pro', 'name' => 'Pro', 'billing_cycle' => 'mensal', 'amount' => 99];
        $this->signedPost($fixed)->assertOk();

        $this->assertSame(1, FullFlowPlan::where('code', 'pro')->count());
    }

    public function test_processing_failure_releases_claim_so_redelivery_works(): void
    {
        $payload = [
            'event_id' => '3f43cb5c-4eb3-46cb-9dc0-0b854e33ba08',
            'event_type' => 'plan.updated',
            'data' => [
                'plan_version' => 1,
                'plan' => ['code' => 'pro', 'name' => 'Pro', 'billing_cycle' => 'mensal', 'amount' => 99],
                'modules' => [['key' => 'cashback']],
            ],
        ];

        // Falha real no meio do processamento (tabela de pivot ausente).
        \Illuminate\Support\Facades\Schema::drop('fullflow_plan_modules');
        $this->signedPost($payload)->assertStatus(500);

        // Claim liberado: o evento não ficou marcado como processado.
        $this->assertSame(0, DB::table('received_webhook_events')->count());

        // Re-entrega do sender, com o schema saudável de volta → aplica.
        \Illuminate\Support\Facades\Schema::create('fullflow_plan_modules', function ($table) {
            $table->id();
            $table->unsignedBigInteger('fullflow_plan_id');
            $table->unsignedBigInteger('fullflow_module_id');
            $table->unsignedInteger('quota_value')->nullable();
            $table->timestamps();
        });
        $this->signedPost($payload)->assertOk();
        $this->assertSame(1, FullFlowPlan::where('code', 'pro')->count());
    }

    public function test_claim_state_machine_lets_exactly_one_delivery_through(): void
    {
        $checker = app(IdempotencyChecker::class);

        // Uma entrega ganha; duplicata simultânea (em voo) leva IN_FLIGHT (425).
        $this->assertSame(IdempotencyChecker::CLAIMED, $checker->claim('evt-corrida', 'assinatura.ativada'));
        $this->assertSame(IdempotencyChecker::IN_FLIGHT, $checker->claim('evt-corrida', 'assinatura.ativada'));

        // Só após o side effect concluir o evento vira DUPLICATE permanente.
        $checker->markProcessed('evt-corrida', 'assinatura.ativada');
        $this->assertSame(IdempotencyChecker::DUPLICATE, $checker->claim('evt-corrida', 'assinatura.ativada'));

        // Falha conhecida reabre imediatamente.
        $checker->release('evt-corrida');
        $this->assertSame(IdempotencyChecker::CLAIMED, $checker->claim('evt-corrida', 'assinatura.ativada'));
    }

    public function test_crash_between_claim_and_processing_is_not_silent_loss(): void
    {
        Event::fake([SubscriptionActivated::class]);
        $payload = [
            'evento_id' => '3f43cb5c-4eb3-46cb-9dc0-0b854e33ba20',
            'evento' => 'assinatura.ativada',
            'dados' => [],
        ];

        // Simula o cenário do review: claim feito e o worker MORREU antes de
        // processar (row em 'processing', sem markProcessed nem release).
        DB::table('received_webhook_events')->insert([
            'event_id' => '3f43cb5c-4eb3-46cb-9dc0-0b854e33ba20',
            'event_type' => 'assinatura.ativada',
            'status' => 'processing',
            'processing_started_at' => now(),
            'received_at' => now(),
        ]);

        // Re-entrega RECENTE: 425 (sender retenta com backoff) — nunca o
        // "200 already processed" que perdia o evento.
        $this->signedPost($payload)->assertStatus(425);
        Event::assertNotDispatched(SubscriptionActivated::class);

        // Claim envelhece além de idempotency_stale_minutes → reclaim.
        DB::table('received_webhook_events')
            ->where('event_id', '3f43cb5c-4eb3-46cb-9dc0-0b854e33ba20')
            ->update(['processing_started_at' => now()->subMinutes(11)]);

        $this->signedPost($payload)->assertOk();
        Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
        $this->assertSame('processed', DB::table('received_webhook_events')
            ->where('event_id', '3f43cb5c-4eb3-46cb-9dc0-0b854e33ba20')
            ->value('status'));
    }

    public function test_claim_state_machine_works_via_cache_when_table_is_absent(): void
    {
        \Illuminate\Support\Facades\Schema::drop('received_webhook_events');
        IdempotencyChecker::resetTableAvailabilityCache();

        $checker = app(IdempotencyChecker::class);

        // Cache::add = set-if-absent (atômico): duplicata em voo → IN_FLIGHT
        // (a chave de claim tem TTL = janela de stale, fazendo o reclaim).
        $this->assertSame(IdempotencyChecker::CLAIMED, $checker->claim('evt-pre-migrate', 'assinatura.ativada'));
        $this->assertSame(IdempotencyChecker::IN_FLIGHT, $checker->claim('evt-pre-migrate', 'assinatura.ativada'));

        $checker->markProcessed('evt-pre-migrate', 'assinatura.ativada');
        $this->assertSame(IdempotencyChecker::DUPLICATE, $checker->claim('evt-pre-migrate', 'assinatura.ativada'));
    }

    public function test_divergent_product_code_is_rejected_when_config_is_set(): void
    {
        config(['fullflow.product_code' => 'app-kicol']);

        $this->signedPost([
            'event_id' => '3f43cb5c-4eb3-46cb-9dc0-0b854e33ba09',
            'event_type' => 'plan.updated',
            'product_code' => 'outro-saas',
            'data' => ['plan_version' => 1, 'plan' => ['code' => 'x']],
        ])->assertStatus(400);
        $this->assertSame(0, DB::table('received_webhook_events')->count());

        // Mesmo produto → processa; payload legado SEM o campo → passa.
        $this->signedPost([
            'event_id' => '3f43cb5c-4eb3-46cb-9dc0-0b854e33ba10',
            'event_type' => 'plan.updated',
            'product_code' => 'app-kicol',
            'data' => ['plan_version' => 1, 'plan' => ['code' => 'pro', 'name' => 'Pro', 'billing_cycle' => 'mensal', 'amount' => 99]],
        ])->assertOk();

        $this->signedPost([
            'evento_id' => '3f43cb5c-4eb3-46cb-9dc0-0b854e33ba11',
            'evento' => 'assinatura.ativada',
            'dados' => [],
        ])->assertOk();
    }
}
