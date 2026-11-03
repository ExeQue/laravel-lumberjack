<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

use Closure;
use ExeQue\Lumberjack\Recorder;
use Illuminate\Container\Container;
use Throwable;

/**
 * Recording must never break the app it watches: a listener or log handler that fails
 * gives up on that one event instead of throwing into the app's request or job.
 */
final class Safely
{
    /** Problems remembered, so a long-running worker meeting many distinct ones does not grow. */
    public const int MAX_PROBLEMS = 100;

    /** @var array<string, true> problems already reported by this process */
    private static array $problems = [];

    /**
     * Something in Lumberjack's own setup is wrong (a bad health check, a release resolver
     * that throws): said once per process, in PHP's error log and as an error in Lumberjack.
     */
    public static function problem(string $message): void
    {
        if (isset(self::$problems[$message])) {
            return;
        }

        // ponytail: past the cap the oldest is forgotten, and may be said once more.
        if (count(self::$problems) >= self::MAX_PROBLEMS) {
            unset(self::$problems[array_key_first(self::$problems)]);
        }

        self::$problems[$message] = true;
        @error_log($message);

        try {
            Container::getInstance()->make(Recorder::class)->record(['type' => 'log', 'level' => 'error', 'message' => $message]);
        } catch (Throwable) {
        }
    }

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
