<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Activity;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Turns a payload into what can be sent, and reads the "@path" values of an attribute's
 * payload from the event it is on.
 */
final class Payload
{
    /**
     * Values as they are sent: a model as its type and key, a date as ISO 8601, an enum
     * as its value, anything arrayable as its array. Nested arrays alike.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function plain(array $payload): array
    {
        return array_map(self::value(...), $payload);
    }

    /**
     * An attribute's payload with each "@path" read from the event (data_get), "@@" for a
     * literal "@", and anything else as written.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function resolve(array $payload, object $event): array
    {
        return array_map(fn (mixed $value): mixed => is_array($value) ? self::resolve($value, $event) : self::read($value, $event), $payload);
    }

    /**
     * One value: "@path" read from the event, "@@..." with the escape taken off, anything
     * else as it is.
     */
    public static function read(mixed $value, object $event): mixed
    {
        if (! is_string($value) || ! str_starts_with($value, '@')) {
            return $value;
        }

        if (str_starts_with($value, '@@')) {
            return substr($value, 1);
        }

        return data_get($event, substr($value, 1));
    }

    private static function value(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Model => Reference::of($value)?->toArray(),
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Arrayable => self::plain($value->toArray()),
            is_array($value) => self::plain($value),
            default => $value,
        };
    }
}
