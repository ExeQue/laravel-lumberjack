<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health;

use ExeQue\Lumberjack\Exceptions\InvalidHealthCheckSlugException;

/**
 * A check's identity in Lumberjack; its history is kept by slug.
 */
final class Slug
{
    public const string PATTERN = '/^[a-z0-9][a-z0-9:._-]{0,99}$/';

    public static function validate(string $slug): string
    {
        if (preg_match(self::PATTERN, $slug) !== 1) {
            throw InvalidHealthCheckSlugException::for($slug);
        }

        return $slug;
    }

    /**
     * Lowercased, anything not allowed as "-", e.g. "fresh:backups:db/daily" to "fresh:backups:db-daily".
     */
    public static function sanitize(string $value): string
    {
        $slug = (string) preg_replace('/[^a-z0-9:._-]+/', '-', strtolower($value));

        return self::validate(substr(trim($slug, ':._-'), 0, 100));
    }
}
