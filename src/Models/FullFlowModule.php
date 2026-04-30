<?php

namespace Kicol\FullFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FullFlowModule extends Model
{
    protected $table = 'fullflow_modules';

    protected $fillable = [
        'slug', 'label', 'description', 'type',
        'visible_to_client', 'synced_at',
    ];

    protected $casts = [
        'visible_to_client' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(FullFlowPlan::class, 'fullflow_plan_modules', 'fullflow_module_id', 'fullflow_plan_id')
            ->withPivot('quota_value')
            ->withTimestamps();
    }
}
