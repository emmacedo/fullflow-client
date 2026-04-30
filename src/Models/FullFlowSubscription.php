<?php

namespace Kicol\FullFlow\Models;

use Illuminate\Database\Eloquent\Model;

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
}
