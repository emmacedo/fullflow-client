<?php

namespace Kicol\FullFlow\Facades;

use Illuminate\Support\Facades\Facade;
use Kicol\FullFlow\FullFlowClient;

/**
 * @method static array createSubscription(array $data)
 * @method static array getSubscription(string $uuid)
 * @method static array cancelSubscription(string $uuid, ?string $motivo = null)
 * @method static array reactivateSubscription(string $uuid, ?string $motivo = null)
 * @method static array listClientSubscriptions(string $documento)
 *
 * @see \Kicol\FullFlow\FullFlowClient
 */
class FullFlow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FullFlowClient::class;
    }
}
