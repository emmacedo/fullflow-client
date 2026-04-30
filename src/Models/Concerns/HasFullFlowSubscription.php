<?php

namespace Kicol\FullFlow\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Kicol\FullFlow\Models\FullFlowSubscription;

trait HasFullFlowSubscription
{
    public function fullflowSubscription(): HasOne
    {
        $modelClass = config('fullflow.subscription_model', FullFlowSubscription::class);
        return $this->hasOne($modelClass);
    }

    public function hasActiveFullFlowSubscription(): bool
    {
        $sub = $this->fullflowSubscription;
        return $sub ? $sub->isAccessAllowed() : false;
    }

    public function fullflowSubscriptionStatus(): ?string
    {
        return $this->fullflowSubscription?->status;
    }
}
