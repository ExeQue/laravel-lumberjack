<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Facades;

use ExeQue\Lumberjack\Health\HealthCheckHandle;
use ExeQue\Lumberjack\Health\HealthRegistry;
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
    /**
     * The health check registry: the one place checks are registered, through its
     * fluent built-ins, check() and add().
     */
    public static function healthChecks(): HealthRegistry
    {
        return app(HealthRegistry::class);
    }

    /**
     * A handle to fire the registered check with this slug from the app's own code.
     * It never registers anything.
     */
    public static function health(string $slug): HealthCheckHandle
    {
        return new HealthCheckHandle(app(HealthRegistry::class), app(), $slug);
    }

    protected static function getFacadeAccessor(): string
    {
        return Handlers::class;
    }
}
