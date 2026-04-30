<?php

namespace Kicol\FullFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Kicol\FullFlow\Models\FullFlowPlan;

class FullFlowSubscription extends Model
{
    protected $table = 'fullflow_subscriptions';

    protected $fillable = [
        'user_id',
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
     * Retorna a quota do módulo (para módulos type='quantity').
     * NULL se módulo é boolean, ou se plano/módulo não existem.
     */
    public function getQuota(string $moduleSlug): ?int
    {
        if (! $this->plan_code) {
            return null;
        }

        $plan = FullFlowPlan::query()
            ->where('code', $this->plan_code)
            ->with(['modules' => fn ($q) => $q->where('slug', $moduleSlug)])
            ->first();

        $module = $plan?->modules->first();
        return $module?->pivot->quota_value;
    }
}
