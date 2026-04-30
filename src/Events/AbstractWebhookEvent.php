<?php

namespace Kicol\FullFlow\Events;

use Illuminate\Foundation\Events\Dispatchable;

abstract class AbstractWebhookEvent
{
    use Dispatchable;

    public function __construct(public array $payload)
    {
    }

    public function eventId(): string
    {
        return $this->payload['evento_id'] ?? '';
    }

    public function subscriptionId(): string
    {
        return $this->payload['assinatura_id'] ?? '';
    }

    public function externalReference(): string
    {
        return $this->payload['referencia_externa'] ?? '';
    }

    public function timestamp(): string
    {
        return $this->payload['timestamp'] ?? '';
    }

    public function data(): array
    {
        return $this->payload['dados'] ?? [];
    }
}
