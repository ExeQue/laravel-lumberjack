<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The period Lumberjack asks a data source for; the screen showing it decides how long.
 */
final readonly class DataRange
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        /** How many seconds one point of a series normally covers; Lumberjack decides. */
        public int $bucket = 3600,
        /** Where a day begins for the buckets. */
        public string $timezone = 'UTC',
    ) {
    }

    public function seconds(): int
    {
        return $this->to->getTimestamp() - $this->from->getTimestamp();
    }

    /**
     * The buckets of the range: from and to in UTC, ready for a query (from inclusive, to
     * exclusive). They follow the clock of the time zone, restarting at each local midnight,
     * so a bucket begins at the same local time unless the clock skipped it, and one the clock repeats is longer. A bucket that began before the range
     * is left out, and the last one ends with the range.
     *
     * @return list<array{from: CarbonImmutable, to: CarbonImmutable}>
     */
    public function buckets(): array
    {
        if ($this->bucket < 1 || 86_400 % $this->bucket !== 0) {
            throw new InvalidArgumentException('A bucket has to divide a day evenly, got ['.$this->bucket.'].');
        }

        $starts = [];
        $last = null;

        for ($day = $this->from->setTimezone($this->timezone)->startOfDay(); $day < $this->to; $day = $day->addDay()->startOfDay()) {
            for ($second = 0; $second < 86_400; $second += $this->bucket) {
                $at = $day->setTime(intdiv($second, 3600), intdiv($second % 3600, 60), $second % 60);

                // A clock that jumped forward lands several times on the same moment.
                if ($at >= $this->from && $at < $this->to && ($last === null || $at > $last)) {
                    $starts[] = $last = $at;
                }
            }
        }

        if ($starts === []) {
            return [];
        }

        return array_map(fn (CarbonImmutable $at, ?CarbonImmutable $next): array => ['from' => $at->utc(), 'to' => ($next ?? $this->to)->utc()], $starts, [...array_slice($starts, 1), null]);
    }
}
