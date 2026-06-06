<?php

namespace Kicol\FullFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Kicol\FullFlow\Models\FullFlowPlan;

class FullFlowSubscription extends Model
{
    protected $table = 'fullflow_subscriptions';

    protected $fillable = [
        'user_id',
        'store_config_id',
        'fullflow_id',
        'reference',
        'plan_code',
        'status',
        'trial_until',
        'current_period_start',
        'current_period_end',
        'amount',
        'billing_cycle',
        'last_synced_at',
    ];

    protected $casts = [
        'trial_until' => 'date',
        'current_period_start' => 'date',
        'current_period_end' => 'date',
        'amount' => 'decimal:2',
        'last_synced_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('fullflow.subscriptions_table', 'fullflow_subscriptions');
    }

    /**
     * Localiza a assinatura da loja com fallback dual (janela de cutover):
     * prefere a coluna nova store_config_id; se não houver match (registros
     * ainda não backfilled), delega ao fallback legado do SaaS.
     *
     * @param int|object $store store_config_id ou model com getKey()
     */
    public static function forStore(int|object $store): ?static
    {
        $storeId = is_object($store) ? (int) $store->getKey() : $store;

        $subscription = static::query()
            ->where('store_config_id', $storeId)
            ->first();

        if ($subscription) {
            return $subscription;
        }

        return static::legacyForStore($storeId);
    }

    /**
     * Fallback legado do forStore(): cada SaaS sobrescreve com a dedução
     * própria (ex.: KicolApps deduz via customer dono da store_config).
     * O pacote, agnóstico de tenant, não tem como deduzir — retorna null.
     */
    protected static function legacyForStore(int $storeConfigId): ?static
    {
        return null;
    }

    public function isAccessAllowed(): bool
    {
        $allowed = config('fullflow.access_allowed_statuses', ['trial', 'ativa', 'past_due', 'cancelamento_agendado']);
        return in_array($this->status, $allowed, true);
    }

    /**
     * Verifica se o plano atual contempla o módulo solicitado.
     * Lê do catálogo LOCAL (tabelas fullflow_plans / fullflow_modules /
     * fullflow_plan_modules) — atualizado pelo comando fullflow:catalog-sync.
     */
    public function canAccess(string $moduleSlug): bool
    {
        if (! $this->isAccessAllowed() || ! $this->plan_code) {
            return false;
        }

        return FullFlowPlan::query()
            ->where('code', $this->plan_code)
            ->whereHas('modules', fn ($q) => $q->where('slug', $moduleSlug))
            ->exists();
    }

    /**
     * Retorna a quota do slug com FONTE DUAL (janela de migração F3):
     *
     * 1. Se o plano tem QUALQUER linha no espelho novo fullflow_plan_features
     *    (plan_id, feature_id → features.key, quota — DDL do catálogo 3.2),
     *    ele é a autoridade — retorna a quota da feature, ou NULL se a key
     *    não está no plano. NÃO cai no legado por key ausente (misturaria
     *    fontes — risco R10).
     * 2. Senão (espelho vazio ou tabelas ainda não migradas), lê o legado
     *    fullflow_plan_modules.quota_value — comportamento idêntico ao v0.7.
     *
     * Call sites antigos chamam com slug LEGADO (ex.: 'email_limit'); o
     * espelho é indexado pela feature_key nova (ex.: 'envio_email') — o mapa
     * config('fullflow.feature_key_aliases') traduz na consulta ao espelho.
     * O fallback legado usa o slug original (módulos legados têm slug antigo).
     *
     * O valor é o cru do pivot; a semântica (NULL/0/N) é do consumidor.
     */
    public function getQuota(string $moduleSlug): ?int
    {
        if (! $this->plan_code) {
            return null;
        }

        $plan = FullFlowPlan::query()
            ->where('code', $this->plan_code)
            ->first();

        if (! $plan) {
            return null;
        }

        if (static::planFeaturesMirrorAvailable()) {
            $hasMirrorRows = \Illuminate\Support\Facades\DB::table('fullflow_plan_features')
                ->where('plan_id', $plan->id)
                ->exists();

            if ($hasMirrorRows) {
                $aliases = config('fullflow.feature_key_aliases', []);
                $featureKey = $aliases[$moduleSlug] ?? $moduleSlug;

                $row = \Illuminate\Support\Facades\DB::table('fullflow_plan_features as fpf')
                    ->join('features as f', 'f.id', '=', 'fpf.feature_id')
                    ->where('fpf.plan_id', $plan->id)
                    ->where('f.key', $featureKey)
                    ->first(['fpf.quota']);

                return $row?->quota !== null ? (int) $row->quota : null;
            }
        }

        $module = $plan->modules()->where('slug', $moduleSlug)->first();

        return $module?->pivot->quota_value;
    }

    /**
     * Guard da janela composer-update→migrate: se as tabelas do espelho novo
     * (fullflow_plan_features + features) ainda não existem, o dual cai no
     * legado em vez de quebrar com SQL error.
     * Cache estático por request — Schema::hasTable é caro para repetir.
     */
    protected static ?bool $planFeaturesMirrorAvailable = null;

    protected static function planFeaturesMirrorAvailable(): bool
    {
        return static::$planFeaturesMirrorAvailable
            ??= \Illuminate\Support\Facades\Schema::hasTable('fullflow_plan_features')
                && \Illuminate\Support\Facades\Schema::hasTable('features');
    }

    /** @internal somente para testes — reseta o cache do guard. */
    public static function resetPlanFeaturesMirrorCache(): void
    {
        static::$planFeaturesMirrorAvailable = null;
    }
}
