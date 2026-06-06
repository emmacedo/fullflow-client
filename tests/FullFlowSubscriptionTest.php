<?php

namespace Kicol\FullFlow\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kicol\FullFlow\FullFlowServiceProvider;
use Kicol\FullFlow\Models\FullFlowModule;
use Kicol\FullFlow\Models\FullFlowPlan;
use Kicol\FullFlow\Models\FullFlowSubscription;
use Orchestra\Testbench\TestCase;

/**
 * forStore() com fallback dual (CL-5) e getQuota() com fonte dual (CL-6) —
 * comportamento provado por teste, não por leitura de código (risco R10).
 */
class FullFlowSubscriptionTest extends TestCase
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

        // Migrations auto-loaded do pacote (fullflow_modules/plans/plan_modules).
        $this->artisan('migrate')->run();

        // fullflow_subscriptions vem de stub publicável — recriada aqui com o
        // shape do stub + coluna nova store_config_id (stub da Etapa 9).
        Schema::create('fullflow_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('store_config_id')->nullable()->index();
            $table->uuid('fullflow_id')->unique();
            $table->string('reference')->unique();
            $table->string('plan_code')->nullable();
            $table->string('status');
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('billing_cycle')->default('mensal');
            $table->timestamps();
        });

        // Catálogo local declarativo (criado no app na F3) — DDL fiel ao
        // catalogo-modulos-features.md 3.1.
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 120);
            $table->string('unit', 32);
            $table->string('period', 16);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Espelho novo (F3) — DDL fiel ao catalogo-modulos-features.md 3.2:
        // PK composta (plan_id, feature_id), feature referenciada por id.
        Schema::create('fullflow_plan_features', function (Blueprint $table) {
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('feature_id');
            $table->unsignedInteger('quota')->nullable();
            $table->primary(['plan_id', 'feature_id']);
        });

        FullFlowSubscription::resetPlanFeaturesMirrorCache();
    }

    private function makeFeature(string $key): int
    {
        return (int) DB::table('features')->insertGetId([
            'key' => $key, 'name' => ucfirst($key), 'unit' => 'unidade',
            'period' => 'monthly', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mirrorQuota(int $planId, string $featureKey, ?int $quota): void
    {
        DB::table('fullflow_plan_features')->insert([
            'plan_id' => $planId,
            'feature_id' => $this->makeFeature($featureKey),
            'quota' => $quota,
        ]);
    }

    private function makePlanWithLegacyQuota(string $code = 'pro', string $slug = 'email_limit', ?int $quotaValue = 50000): FullFlowPlan
    {
        $plan = FullFlowPlan::create([
            'code' => $code, 'name' => 'Pro', 'billing_cycle' => 'mensal', 'amount' => 99,
        ]);
        $module = FullFlowModule::create([
            'slug' => $slug, 'label' => 'E-mails', 'type' => 'quantity',
        ]);
        $plan->modules()->attach($module->id, ['quota_value' => $quotaValue]);

        return $plan;
    }

    private function makeSubscription(array $overrides = []): FullFlowSubscription
    {
        return FullFlowSubscription::create(array_merge([
            'fullflow_id' => (string) \Illuminate\Support\Str::uuid(),
            'reference' => 'ref_' . uniqid(),
            'plan_code' => 'pro',
            'status' => 'ativa',
            'amount' => 99,
            'billing_cycle' => 'mensal',
        ], $overrides));
    }

    // ---- CL-6: getQuota dual ----

    public function test_quota_falls_back_to_legacy_quota_value_when_mirror_is_empty(): void
    {
        $this->makePlanWithLegacyQuota();
        $sub = $this->makeSubscription();

        // Espelho novo vazio (estado pré-backfill da F3) → fonte legada,
        // comportamento idêntico ao client v0.7.
        $this->assertSame(50000, $sub->getQuota('email_limit'));
    }

    public function test_quota_reads_real_migration_data_via_legacy_alias(): void
    {
        // Dado REAL pós-migração (prova de paridade da F3, migracao.md 460):
        // espelho indexado pela key NOVA 'envio_email' com a cota da Ducena;
        // call site antigo segue chamando com o slug LEGADO 'email_limit'.
        $plan = $this->makePlanWithLegacyQuota(quotaValue: 999); // legado divergente de propósito
        $sub = $this->makeSubscription();

        $this->mirrorQuota($plan->id, 'envio_email', 50000);

        $this->assertSame(50000, $sub->getQuota('email_limit'));   // alias legado→novo
        $this->assertSame(50000, $sub->getQuota('envio_email'));   // key nova direto
    }

    public function test_quota_reads_new_mirror_for_keys_without_alias(): void
    {
        $plan = $this->makePlanWithLegacyQuota();
        $sub = $this->makeSubscription();

        // Key nova sem entrada no mapa de aliases: consulta direta ao espelho.
        $this->mirrorQuota($plan->id, 'quantidade_compradores', 1500);

        $this->assertSame(1500, $sub->getQuota('quantidade_compradores'));
    }

    public function test_quota_does_not_mix_sources_when_key_missing_from_populated_mirror(): void
    {
        $plan = $this->makePlanWithLegacyQuota();
        $sub = $this->makeSubscription();

        // Plano TEM espelho novo, mas para outra key. O legado tem 50000 para
        // email_limit — cair nele misturaria fontes (anti-R10): deve dar NULL.
        $this->mirrorQuota($plan->id, 'envio_whatsapp_outra', 100);

        $this->assertNull($sub->getQuota('email_limit'));
    }

    public function test_quota_zero_and_null_in_mirror_keep_their_meaning(): void
    {
        $plan = $this->makePlanWithLegacyQuota();
        $sub = $this->makeSubscription();

        $this->mirrorQuota($plan->id, 'bloqueada', 0);
        $this->mirrorQuota($plan->id, 'ilimitada', null);

        $this->assertSame(0, $sub->getQuota('bloqueada'));   // 0 ≠ null (bloqueado)
        $this->assertNull($sub->getQuota('ilimitada'));      // NULL = ilimitado
    }

    public function test_quota_falls_back_to_legacy_when_mirror_table_does_not_exist(): void
    {
        $this->makePlanWithLegacyQuota();
        $sub = $this->makeSubscription();

        // Janela composer-update → migrate: a tabela espelho ainda não existe.
        Schema::drop('fullflow_plan_features');
        FullFlowSubscription::resetPlanFeaturesMirrorCache();

        $this->assertSame(50000, $sub->getQuota('email_limit'));
    }

    public function test_quota_returns_null_without_plan_code_or_unknown_plan(): void
    {
        $sub = $this->makeSubscription(['plan_code' => null]);
        $this->assertNull($sub->getQuota('email_limit'));

        $orphan = $this->makeSubscription(['plan_code' => 'inexistente']);
        $this->assertNull($orphan->getQuota('email_limit'));
    }

    // ---- CL-5: forStore dual ----

    public function test_for_store_finds_by_store_config_id(): void
    {
        $this->makePlanWithLegacyQuota();
        $sub = $this->makeSubscription(['store_config_id' => 5]);

        $this->assertTrue($sub->is(FullFlowSubscription::forStore(5)));
        $this->assertNull(FullFlowSubscription::forStore(99));
    }

    public function test_for_store_uses_legacy_fallback_only_when_primary_misses(): void
    {
        $this->makePlanWithLegacyQuota();

        // Registro pré-backfill: store_config_id ainda NULL.
        $legacySub = $this->makeSubscription(['reference' => 'kicol_customer_7']);

        $resolved = TenantAwareSubscription::forStore(5);
        $this->assertNotNull($resolved);
        $this->assertSame($legacySub->id, $resolved->id);
        $this->assertSame(1, TenantAwareSubscription::$legacyCalls);

        // Quando o primário acha, o fallback NÃO é consultado.
        TenantAwareSubscription::$legacyCalls = 0;
        $this->makeSubscription(['store_config_id' => 5, 'reference' => 'kicol_store_5']);

        $primary = TenantAwareSubscription::forStore(5);
        $this->assertSame('kicol_store_5', $primary->reference);
        $this->assertSame(0, TenantAwareSubscription::$legacyCalls);
    }
}

/**
 * Simula a especialização que o SaaS faz (ex.: App\Models\FullFlowSubscription
 * no KicolApps deduz a loja via customer) — aqui, via reference legada.
 */
class TenantAwareSubscription extends FullFlowSubscription
{
    public static int $legacyCalls = 0;

    protected static function legacyForStore(int $storeConfigId): ?static
    {
        static::$legacyCalls++;

        // Dedução fake: storeConfigId 5 pertence ao customer 7.
        return static::query()
            ->whereNull('store_config_id')
            ->where('reference', 'kicol_customer_7')
            ->first();
    }
}
