<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Concerns;

use ExeQue\Lumberjack\Health\Slug;
use Illuminate\Support\Str;

/**
 * The slug and thresholds every built-in check takes as constructor parameters:
 * int $degradeAfter, int $recoverAfter, int $timeout and ?string $slug.
 *
 * @property-read ?string $slug
 * @property-read int $degradeAfter
 * @property-read int $recoverAfter
 * @property-read int $timeout
 */
trait ConfiguresHealthCheck
{
    public function slug(): string
    {
        return $this->slug === null ? Slug::sanitize($this->defaultSlug()) : Slug::validate($this->slug);
    }

    public function degradeAfter(): int
    {
        return $this->degradeAfter;
    }

    public function recoverAfter(): int
    {
        return $this->recoverAfter;
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    /**
     * The slug when none is given: the class name in kebab case, e.g. "stripe-health-check".
     */
    protected function defaultSlug(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    /**
     * Milliseconds since $start (from hrtime(true)), to three decimals.
     */
    protected static function since(int|float $start): float
    {
        return round((hrtime(true) - $start) / 1e6, 3);
    }
}
