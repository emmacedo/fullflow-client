<?php

namespace Kicol\FullFlow\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kicol\FullFlow\Webhook\WebhookPayload;

abstract class AbstractWebhookEvent
{
    use Dispatchable;

    public function __construct(public array $payload)
    {
    }

    // Accessors duais PT/EN (CL-7, cutover 4.9): listeners do SaaS continuam
    // funcionando quando o FullFlow trocar o envelope para inglês.

    public function eventId(): string
    {
        return WebhookPayload::eventId($this->payload);
    }

    public function subscriptionId(): string
    {
        return WebhookPayload::subscriptionId($this->payload);
    }

    public function externalReference(): string
    {
        return WebhookPayload::externalReference($this->payload);
    }

    public function timestamp(): string
    {
        return $this->payload['timestamp'] ?? '';
    }

    public function data(): array
    {
        return WebhookPayload::data($this->payload);
    }
}
