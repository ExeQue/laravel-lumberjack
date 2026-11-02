<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\CallQueuedClosure;
use Traversable;
use UnitEnum;

/**
 * A queued job's public properties, made small and safe to send: scalars as
 * they are, models as Class#id, collections and arrays shortened, anything
 * else as its class name. Encrypted jobs send nothing.
 */
final class JobParameters
{
    public const int MAX_STRING = 200;

    public const int MAX_ITEMS = 10;

    public const int MAX_DEPTH = 2;

    /** Queue plumbing every job has; not what the job is about. */
    private const array PLUMBING = [
        'job', 'connection', 'queue', 'delay', 'afterCommit', 'middleware', 'chained', 'chainConnection',
        'chainQueue', 'chainCatchCallbacks', 'tries', 'timeout', 'maxExceptions', 'failOnTimeout', 'backoff',
        'retryUntil', 'deleteWhenMissingModels', 'messageGroup', 'deduplicator', 'shouldBeEncrypted',
        'debounceOwner', 'uniqueLockOwner',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public static function from(mixed $job): ?array
    {
        if (! is_object($job) || $job instanceof ShouldBeEncrypted || $job instanceof CallQueuedClosure || $job instanceof Closure) {
            return null;
        }

        // Called from outside the class, so only public properties.
        $properties = array_diff_key(get_object_vars($job), array_flip(self::PLUMBING));
        $parameters = [];

        foreach ($properties as $name => $value) {
            $parameters[$name] = self::value($value);
        }

        return $parameters === [] ? null : $parameters;
    }

    /**
     * One value made small and safe to send.
     */
    public static function value(mixed $value, int $depth = 0): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            is_string($value) => mb_strlen($value) > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING).'…' : $value,
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Model => $value::class.'#'.(is_scalar($value->getKey()) ? $value->getKey() : '?'),
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            is_array($value), $value instanceof Traversable => self::list($value, $depth),
            is_object($value) => $value::class,
            default => get_debug_type($value),
        };
    }

    /**
     * @param  iterable<mixed>  $items
     * @return array<int|string, mixed>|string
     */
    private static function list(iterable $items, int $depth): array|string
    {
        if ($depth >= self::MAX_DEPTH) {
            return '[…]';
        }

        $out = [];
        $count = 0;

        foreach ($items as $key => $item) {
            if ($count++ >= self::MAX_ITEMS) {
                $out['…'] = 'more';

                break;
            }

            $out[$key] = self::value($item, $depth + 1);
        }

        return $out;
    }
}
