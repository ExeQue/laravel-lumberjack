<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

use Closure;
use Throwable;

/**
 * Recording must never break the app it watches: a listener or log handler that fails
 * gives up on that one event instead of throwing into the app's request or job.
 */
final class Safely
{
    public static function run(callable $callback, mixed ...$arguments): void
    {
        try {
            $callback(...$arguments);
        } catch (Throwable $e) {
            // ponytail: to PHP's error log, not Laravel's: Laravel's may be Lumberjack itself.
            @error_log('Lumberjack could not record an event: '.$e::class.': '.$e->getMessage());
        }
    }

    public static function wrap(callable $callback): Closure
    {
        return static function (mixed ...$arguments) use ($callback): void {
            self::run($callback, ...$arguments);
        };
    }
}
