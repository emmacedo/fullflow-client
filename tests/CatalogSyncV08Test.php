<?php

namespace Kicol\FullFlow\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Kicol\FullFlow\FullFlowServiceProvider;
use Kicol\FullFlow\Models\FullFlowPlan;
use Orchestra\Testbench\TestCase;

/**
 * CL-8 (push do catálogo local) + pullCatalog v0.8 (superset do GET,
 * espelho de features, soft-flag de ausentes).
 */
class CatalogSyncV08Test extends TestCase
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
        \Kicol\FullFlow\Models\FullFlowSubscription::resetPlanFeaturesMirrorCache();
    }

    private function createLocalCatalogTables(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('availability', 16)->default('general');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 120);
            $table->string('unit', 32);
            $table->string('period', 16);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('module_features', function (Blueprint $table) {
            $table->unsignedBigInteger('module_id');
            $table->unsignedBigInteger('feature_id');
            $table->primary(['module_id', 'feature_id']);
        });
        Schema::create('fullflow_plan_features', function (Blueprint $table) {
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('feature_id');
            $table->unsignedInteger('quota')->nullable();
            $table->primary(['plan_id', 'feature_id']);
        });
    }

    // ---- CL-8: push lê tabelas locais (fallback config) ----

    public function test_push_sends_local_catalog_as_en_contract(): void
    {
        $this->createLocalCatalogTables();
        $moduleId = DB::table('modules')->insertGetId([
            'key' => 'cashback', 'name' => 'Cashback', 'description' => 'Cashback por pedido',
            'availability' => 'general', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $featureId = DB::table('features')->insertGetId([
            'key' => 'envio_email', 'name' => 'Envio de e-mails', 'unit' => 'email',
            'period' => 'monthly', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('module_features')->insert(['module_id' => $moduleId, 'feature_id' => $featureId]);

        Http::fake(['*' => Http::response(['criados' => [], 'atualizados' => [], 'arquivados' => [], 'total' => 1], 200)]);

        $this->artisan('fullflow:catalog-sync', ['--skip-pull' => true])->assertSuccessful();

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), '/modulos/sync')
                && $data['modules'] === [[
                    'key' => 'cashback', 'label' => 'Cashback',
                    'description' => 'Cashback por pedido', 'availability' => 'general',
                ]]
                && $data['features'] === [[
                    'key' => 'envio_email', 'label' => 'Envio de e-mails',
                    'unit' => 'email', 'period' => 'monthly',
                ]]
                && $data['module_features'] === [['module_key' => 'cashback', 'feature_key' => 'envio_email']]
                && ! isset($data['modulos']); // contrato EN, não o legado PT
        });
    }

    public function test_push_falls_back_to_config_when_local_tables_absent(): void
    {
        config(['fullflow.modules' => [
            ['slug' => 'cashback', 'label' => 'Cashback', 'tipo' => 'boolean'],
        ]]);

        Http::fake(['*' => Http::response(['total' => 1], 200)]);

        $this->artisan('fullflow:catalog-sync', ['--skip-pull' => true])->assertSuccessful();

        Http::assertSent(function ($request) {
            $data = $request->data();

            return isset($data['modulos']) // formato PT legado
                && $data['modulos'][0]['slug'] === 'cashback'
                && ! isset($data['modules']);
        });
    }

    // ---- pullCatalog v0.8 ----

    private function planosResponse(): array
    {
        return ['planos' => [[
            'code' => 'pro', 'name' => 'Pro', 'billing_cycle' => 'mensal',
            'amount' => 99.0, 'plan_version' => 7,
            // superset: módulos com label/tipo, features com label/unit/period
            'modulos' => [['slug' => 'cashback', 'label' => 'Cashback', 'tipo' => 'boolean', 'quota' => null]],
            'features' => [[
                'key' => 'envio_email', 'label' => 'E-mails', 'unit' => 'email',
                'period' => 'monthly', 'quota' => 50000,
            ]],
        ]]];
    }

    public function test_pull_parses_features_superset_using_only_key_and_quota(): void
    {
        $this->createLocalCatalogTables();
        $featureId = DB::table('features')->insertGetId([
            'key' => 'envio_email', 'name' => 'E-mails', 'unit' => 'email',
            'period' => 'monthly', 'created_at' => now(), 'updated_at' => now(),
        ]);

        Http::fake(['*' => Http::response($this->planosResponse(), 200)]);

        app(\Kicol\FullFlow\FullFlowClient::class)->pullCatalog();

        $plan = FullFlowPlan::where('code', 'pro')->firstOrFail();
        $this->assertSame(7, $plan->plan_version);
        $this->assertTrue($plan->active);

        $mirror = DB::table('fullflow_plan_features')->where('plan_id', $plan->id)->get();
        $this->assertCount(1, $mirror);
        $this->assertSame($featureId, (int) $mirror[0]->feature_id);
        $this->assertSame(50000, (int) $mirror[0]->quota);
    }

    public function test_pull_marks_missing_plans_inactive_instead_of_deleting(): void
    {
        FullFlowPlan::create([
            'code' => 'antigo', 'name' => 'Antigo', 'billing_cycle' => 'mensal',
            'amount' => 50, 'active' => true,
        ]);

        Http::fake(['*' => Http::response($this->planosResponse(), 200)]);

        app(\Kicol\FullFlow\FullFlowClient::class)->pullCatalog();

        $antigo = FullFlowPlan::where('code', 'antigo')->first();
        $this->assertNotNull($antigo); // NÃO deletado (histórico preservado)
        $this->assertFalse($antigo->active);
    }

    public function test_pull_works_without_feature_mirror_tables(): void
    {
        // Janela pré-F3: tabelas features/fullflow_plan_features não existem.
        Http::fake(['*' => Http::response($this->planosResponse(), 200)]);

        $result = app(\Kicol\FullFlow\FullFlowClient::class)->pullCatalog();

        $this->assertSame(1, $result['planos']);
        $this->assertSame(7, FullFlowPlan::where('code', 'pro')->value('plan_version'));
    }
}
