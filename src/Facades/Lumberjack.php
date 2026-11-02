<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Facades;

use ExeQue\Lumberjack\Webhooks\Handlers;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Handlers on(\ExeQue\Lumberjack\Webhooks\Status|list<\ExeQue\Lumberjack\Webhooks\Status> $statuses, callable $handler)
 * @method static Handlers onException(string|list<string> $classes, \ExeQue\Lumberjack\Webhooks\Status|list<\ExeQue\Lumberjack\Webhooks\Status> $statuses, callable $handler)
 *
 * @see Handlers
 */
class Lumberjack extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Handlers::class;
    }
}
