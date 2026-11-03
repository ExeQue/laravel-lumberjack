<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health;

/**
 * Where Lumberjack says a check stands. Unknown is a slug Lumberjack has not told
 * this app about (yet), or no answer from it at all.
 */
enum HealthState: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Down = 'down';
    case Recovering = 'recovering';
    case Stale = 'stale';
    case Pending = 'pending';
    case Unknown = 'unknown';

    /**
     * Only ok: a recovering check is still part of its outage.
     */
    public function isHealthy(): bool
    {
        return $this === self::Ok;
    }

    public function isBad(): bool
    {
        return $this === self::Degraded || $this === self::Down;
    }
}
