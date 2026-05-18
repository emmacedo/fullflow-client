<?php

namespace Kicol\FullFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FullFlowPlan extends Model
{
    protected $table = 'fullflow_plans';

    protected $fillable = [
        'code', 'name', 'description', 'billing_cycle', 'amount',
        'is_custom_pricing', 'trial_days', 'sort_order', 'synced_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_custom_pricing' => 'boolean',
        'trial_days' => 'integer',
        'sort_order' => 'integer',
        'synced_at' => 'datetime',
    ];

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(FullFlowModule::class, 'fullflow_plan_modules', 'fullflow_plan_id', 'fullflow_module_id')
            ->withPivot('quota_value')
            ->withTimestamps();
    }
}
