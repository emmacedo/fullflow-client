<?php

namespace Kicol\FullFlow\Webhook;

use Illuminate\Support\Facades\Cache;

class IdempotencyChecker
{
    public function __construct(public int $ttlHours = 24) {}

    public function wasProcessed(string $eventId): bool
    {
        return Cache::has($this->key($eventId));
    }

    public function markProcessed(string $eventId): void
    {
        Cache::put($this->key($eventId), true, now()->addHours($this->ttlHours));
    }

    private function key(string $eventId): string
    {
        return "fullflow:webhook_event:{$eventId}";
    }
}
