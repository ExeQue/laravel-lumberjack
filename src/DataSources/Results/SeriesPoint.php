<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources\Results;

use DateTimeInterface;
use ExeQue\Lumberjack\Exceptions\InvalidDataSourceResultException;

/** One point of a SeriesResult. */
final readonly class SeriesPoint
{
    /** The end of year 9999, the last time Lumberjack can show. */
    private const int MAX_TIME = 253_402_300_799;

    public int $at;

    public int|float|null $value;

    /**
     * @param  DateTimeInterface|int  $at  a date, or a Unix time
     */
    public function __construct(DateTimeInterface|int $at, int|float|null $value)
    {
        $this->at = $at instanceof DateTimeInterface ? $at->getTimestamp() : $at;

        if ($this->at < 0 || $this->at > self::MAX_TIME) {
            throw new InvalidDataSourceResultException('Expected a time between 1970 and the year 9999.');
        }

        $this->value = $value === null ? null : Cell::number($value);
    }
}
