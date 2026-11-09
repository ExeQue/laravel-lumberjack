<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources\Results;

use ExeQue\Lumberjack\Exceptions\InvalidDataSourceResultException;
use Stringable;

/**
 * The checks every result shares: numbers JSON can carry and text of a bounded length.
 *
 * @internal
 */
final class Cell
{
    private const int MAX_TEXT = 500;

    public static function number(int|float $value): int|float
    {
        return is_finite($value) ? $value : throw new InvalidDataSourceResultException('Expected a finite number.');
    }

    /** Equal as a widget would show it: integers exactly, anything with a float to a relative tolerance. */
    public static function equal(mixed $actual, mixed $expected): bool
    {
        $numbers = (is_int($actual) || is_float($actual)) && (is_int($expected) || is_float($expected));

        return $numbers && ! (is_int($actual) && is_int($expected))
            ? abs($actual - $expected) <= 1e-12 * max(1e-1, abs($actual), abs($expected))
            : $actual === $expected;
    }

    public static function text(mixed $value): string
    {
        if (! is_scalar($value) && ! $value instanceof Stringable) {
            throw new InvalidDataSourceResultException('Expected text.');
        }

        return mb_substr(mb_scrub((string) $value), 0, self::MAX_TEXT);
    }

    public static function value(mixed $value): int|float|string|null
    {
        return match (true) {
            $value === null, is_int($value) => $value,
            is_float($value) => self::number($value),
            is_bool($value) => $value ? 'true' : 'false',
            default => self::text($value),
        };
    }
}
