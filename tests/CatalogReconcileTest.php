<?php

namespace Kicol\FullFlow\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Kicol\FullFlow\Events\FullFlowPlanUpdated;
use Kicol\FullFlow\FullFlowServiceProvider;
use Kicol\FullFlow\Models\FullFlowPlan;
use Kicol\FullFlow\Webhook\Handlers\PlanUpdatedHandler;
use Orchestra\Testbench\TestCase;

/**
 * fullflow:catalog-reconcile (sync 4.10): drift de plan_version aplicado
 * pelo MESMO handler do webhook (com evento de invalidação), ausentes
 * marcados inativos, sem drift = no-op.
 */
class CatalogReconcileTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FullFlowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('fullflow.base_url', 'https://fullflow.test/api/v1');
        $app['config']->set('fullflow.api_key', 'test-key');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();
        PlanUpdatedHandler::resetFeatureMirrorCache();

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
        DB::table('features')->insert([
            'key' => 'envio_email', 'name' => 'E-mails', 'unit' => 'email',
            'period' => 'monthly', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function remotePlanos(int $version = 5): array
    {
        return ['planos' => [[
            'code' => 'pro', 'name' => 'Pro', 'billing_cycle' => 'mensal', 'amount' => 99.0,
            'plan_version' => $version,
            'modulos' => [['slug' => 'cashback', 'quota' => null]],
            'features' => [['key' => 'envio_email', 'label' => 'E-mails', 'unit' => 'email', 'period' => 'monthly', 'quota' => 50000]],
        ]]];
    }

    public function test_version_drift_is_applied_via_handler_with_invalidation_event(): void
    {
        Event::fake([FullFlowPlanUpdated::class]);
        FullFlowPlan::create(['code' => 'pro', 'name' => 'Pro', 'billing_cycle' => 'mensal', 'amount' => 99, 'plan_version' => 3]);

        Http::fake(['*' => Http::response($this->remotePlanos(version: 5), 200)]);

        $this->artisan('fullflow:catalog-reconcile')->assertSuccessful();

        $plan = FullFlowPlan::where('code', 'pro')->firstOrFail();
        $this->assertSame(5, $plan->plan_version);
        $this->assertSame(50000, (int) DB::table('fullflow_plan_features')->where('plan_id', $plan->id)->value('quota'));
        Event::assertDispatched(FullFlowPlanUpdated::class); // SaaS invalida caches
    }

    public function test_no_drift_is_noop(): void
    {
        Event::fake([FullFlowPlanUpdated::class]);
        FullFlowPlan::create(['code' => 'pro', 'name' => 'Pro', 'billing_cycle' => 'mensal', 'amount' => 99, 'plan_version' => 5]);

        Http::fake(['*' => Http::response($this->remotePlanos(version: 5), 200)]);

        $this->artisan('fullflow:catalog-reconcile')->assertSuccessful();

        Event::assertNotDispatched(FullFlowPlanUpdated::class);
        $this->assertSame(0, DB::table('fullflow_plan_features')->count()); // nada reaplicado
    }

    public function test_weekly_schedule_is_registered_by_the_provider(): void
    {
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

        $event = collect($schedule->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'fullflow:catalog-reconcile'));

        $this->assertNotNull($event, 'Schedule do catalog-reconcile não registrado.');
        // weeklyOn(6, '03:30') → cron '30 3 * * 6' (sábado).
        $this->assertSame('30 3 * * 6', $event->expression);
    }

    public function test_locally_active_plan_missing_from_remote_is_deactivated(): void
    {
        FullFlowPlan::create(['code' => 'sumido', 'name' => 'Sumido', 'billing_cycle' => 'mensal', 'amount' => 10, 'plan_version' => 1, 'active' => true]);

        Http::fake(['*' => Http::response($this->remotePlanos(), 200)]);

        $this->artisan('fullflow:catalog-reconcile')->assertSuccessful();

        $sumido = FullFlowPlan::where('code', 'sumido')->firstOrFail();
        $this->assertFalse($sumido->active); // marcado, não deletado
    }
}
