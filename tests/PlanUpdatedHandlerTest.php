<?php

namespace Kicol\FullFlow\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Kicol\FullFlow\Events\FullFlowPlanUpdated;
use Kicol\FullFlow\FullFlowServiceProvider;
use Kicol\FullFlow\Models\FullFlowPlan;
use Kicol\FullFlow\Webhook\Handlers\PlanUpdatedHandler;
use Orchestra\Testbench\TestCase;

/**
 * CL-4: idempotência em 3 camadas do plan.updated — gate de plan_version,
 * transação atômica, e aplicação fiel do snapshot (módulos + features).
 * (A camada 1, event_id, é do receiver — coberta no WebhookReceiverTest.)
 */
class PlanUpdatedHandlerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FullFlowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();

        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 120);
            $table->string('unit', 32);
            $table->string('period', 16);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fullflow_plan_features', function (Blueprint $table) {
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('feature_id');
            $table->unsignedInteger('quota')->nullable();
            $table->primary(['plan_id', 'feature_id']);
        });

        PlanUpdatedHandler::resetFeatureMirrorCache();
    }

    private function makeFeature(string $key): int
    {
        return (int) DB::table('features')->insertGetId([
            'key' => $key, 'name' => ucfirst($key), 'unit' => 'unidade',
            'period' => 'monthly', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Payload fiel ao contrato 4.2 emitido pelo PlanWebhookEnqueuer (Etapa 6). */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'event_id' => '01HXEXAMPLE0000000000000PL',
            'event_type' => 'plan.updated',
            'occurred_at' => '2026-06-05T12:00:00-03:00',
            'product_code' => 'app-kicol',
            'data' => [
                'plan_version' => 3,
                'plan' => [
                    'code' => 'kicolapps_professional',
                    'name' => 'Professional',
                    'description' => null,
                    'billing_cycle' => 'mensal',
                    'amount' => 397.0,
                    'is_custom_pricing' => false,
                    'trial_days' => 7,
                    'visible_to_client' => true,
                    'active' => true,
                    'sort_order' => 2,
                ],
                'modules' => [['key' => 'cashback'], ['key' => 'mensagem_em_massa']],
                'features' => [['key' => 'envio_email', 'quota' => 50000]],
            ],
        ], $overrides);
    }

    public function test_applies_new_plan_with_modules_and_feature_mirror(): void
    {
        Event::fake([FullFlowPlanUpdated::class]);
        $emailId = $this->makeFeature('envio_email');

        $result = app(PlanUpdatedHandler::class)->handle($this->payload());

        $this->assertTrue($result);
        $plan = FullFlowPlan::where('code', 'kicolapps_professional')->firstOrFail();
        $this->assertSame(3, $plan->plan_version);
        $this->assertTrue($plan->active);
        $this->assertEqualsCanonicalizing(
            ['cashback', 'mensagem_em_massa'],
            $plan->modules()->pluck('slug')->all()
        );
        $mirror = DB::table('fullflow_plan_features')->where('plan_id', $plan->id)->get();
        $this->assertCount(1, $mirror);
        $this->assertSame($emailId, (int) $mirror[0]->feature_id);
        $this->assertSame(50000, (int) $mirror[0]->quota);

        Event::assertDispatched(FullFlowPlanUpdated::class);
    }

    public function test_discards_stale_plan_version_without_touching_mirror(): void
    {
        Event::fake([FullFlowPlanUpdated::class]);
        $this->makeFeature('envio_email');
        app(PlanUpdatedHandler::class)->handle($this->payload()); // versão 3
        Event::assertDispatchedTimes(FullFlowPlanUpdated::class, 1);

        // Entrega fora de ordem: versão 2 com quota diferente NÃO aplica.
        $stale = $this->payload([
            'data' => ['plan_version' => 2, 'features' => [['key' => 'envio_email', 'quota' => 1]]],
        ]);
        $result = app(PlanUpdatedHandler::class)->handle($stale);

        $this->assertTrue($result); // descarte é estado final (200 para o sender)
        $plan = FullFlowPlan::where('code', 'kicolapps_professional')->firstOrFail();
        $this->assertSame(3, $plan->plan_version);
        $this->assertSame(50000, (int) DB::table('fullflow_plan_features')->where('plan_id', $plan->id)->value('quota'));
        Event::assertDispatchedTimes(FullFlowPlanUpdated::class, 1); // sem novo evento
    }

    public function test_transaction_is_atomic_failure_midway_persists_nothing(): void
    {
        $this->makeFeature('envio_email');

        // Falha no meio da transação: sync de módulos quebra (tabela ausente).
        Schema::drop('fullflow_plan_modules');

        try {
            app(PlanUpdatedHandler::class)->handle($this->payload());
            $this->fail('Esperava QueryException.');
        } catch (\Illuminate\Database\QueryException) {
            // esperado
        }

        // Tudo ou nada: nem o plano, nem o espelho de features.
        $this->assertSame(0, FullFlowPlan::count());
        $this->assertSame(0, DB::table('fullflow_plan_features')->count());
    }

    public function test_unknown_feature_key_is_skipped_without_stub_and_rest_applies(): void
    {
        $this->makeFeature('envio_email');

        // 'envio_fax' não existe no catálogo local (autoridade do dev).
        $payload = $this->payload([
            'data' => ['features' => [
                ['key' => 'envio_email', 'quota' => 50000],
                ['key' => 'envio_fax', 'quota' => 10],
            ]],
        ]);

        app(PlanUpdatedHandler::class)->handle($payload);

        $plan = FullFlowPlan::where('code', 'kicolapps_professional')->firstOrFail();
        $this->assertSame(1, DB::table('fullflow_plan_features')->where('plan_id', $plan->id)->count());
        $this->assertSame(0, DB::table('features')->where('key', 'envio_fax')->count()); // sem stub
    }

    public function test_pt_and_en_envelopes_produce_identical_result(): void
    {
        $this->makeFeature('envio_email');
        $en = $this->payload();

        app(PlanUpdatedHandler::class)->handle($en);
        $afterEn = $this->snapshotPlan();

        // Reset total e reaplica com envelope PT (dados em vez de data).
        DB::table('fullflow_plan_features')->delete();
        DB::table('fullflow_plan_modules')->delete();
        FullFlowPlan::query()->delete();

        $pt = ['evento_id' => $en['event_id'], 'evento' => 'plan.updated', 'dados' => $en['data']];
        app(PlanUpdatedHandler::class)->handle($pt);

        $this->assertSame($afterEn, $this->snapshotPlan());
    }

    public function test_deactivation_payload_marks_plan_inactive(): void
    {
        $this->makeFeature('envio_email');
        app(PlanUpdatedHandler::class)->handle($this->payload());

        $deleted = $this->payload([
            'data' => ['plan_version' => 4, 'plan' => ['active' => false]],
        ]);
        app(PlanUpdatedHandler::class)->handle($deleted);

        $plan = FullFlowPlan::where('code', 'kicolapps_professional')->firstOrFail();
        $this->assertFalse($plan->active);
        $this->assertSame(4, $plan->plan_version);
    }

    private function snapshotPlan(): array
    {
        $plan = FullFlowPlan::where('code', 'kicolapps_professional')->firstOrFail();

        return [
            'version' => $plan->plan_version,
            'modules' => $plan->modules()->pluck('slug')->sort()->values()->all(),
            'features' => DB::table('fullflow_plan_features')
                ->where('plan_id', $plan->id)
                ->orderBy('feature_id')
                ->get(['feature_id', 'quota'])
                ->map(fn ($r) => [(int) $r->feature_id, $r->quota === null ? null : (int) $r->quota])
                ->all(),
        ];
    }
}
