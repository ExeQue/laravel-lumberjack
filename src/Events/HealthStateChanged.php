<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Events;

use ExeQue\Lumberjack\Health\HealthState;
use ExeQue\Lumberjack\Health\HealthStateSnapshot;

/**
 * A check's state as Lumberjack reports it changed; from Unknown the first time
 * this app hears of the slug, to Unknown when Lumberjack no longer lists it.
 */
final readonly class HealthStateChanged
{
    public function __construct(
        public string $slug,
        public HealthState $from,
        public HealthState $to,
        public HealthStateSnapshot $snapshot,
    ) {
    }
}
