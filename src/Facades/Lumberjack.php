<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Facades;

use ExeQue\Lumberjack\Health\HealthCheckHandle;
use ExeQue\Lumberjack\Health\HealthRegistry;
use ExeQue\Lumberjack\Health\HealthStates;
use ExeQue\Lumberjack\Health\HealthStateSnapshot;
use ExeQue\Lumberjack\PendingAlert;
use ExeQue\Lumberjack\Recorder;
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
     * A handle to fire the registered check with this slug from the app's own code, or
     * to read where Lumberjack says any check stands. It never registers anything.
     */
    public static function health(string $slug): HealthCheckHandle
    {
        return new HealthCheckHandle(app(HealthRegistry::class), app(), $slug);
    }

    /**
     * Every check Lumberjack knows for this app, by slug, as it last said they stand.
     *
     * @return array<string, HealthStateSnapshot>
     */
    public static function healthStates(): array
    {
        return app(HealthStates::class)->all();
    }

    /**
     * A custom alert with this title, sent to the channels of its topic by send().
     */
    public static function alert(string $title): PendingAlert
    {
        return new PendingAlert(app(Recorder::class), $title);
    }

    protected static function getFacadeAccessor(): string
    {
        return Handlers::class;
    }
}
