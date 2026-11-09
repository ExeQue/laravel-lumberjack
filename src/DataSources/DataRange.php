<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources;

use Carbon\CarbonImmutable;

/**
 * The period Lumberjack asks a data source for; the screen showing it decides how long.
 */
final readonly class DataRange
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {
    }

    public function seconds(): int
    {
        return $this->to->getTimestamp() - $this->from->getTimestamp();
    }
}
