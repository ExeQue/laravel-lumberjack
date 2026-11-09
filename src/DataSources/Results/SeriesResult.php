<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources\Results;

use ExeQue\Lumberjack\Contracts\DataSourceResult;
use ExeQue\Lumberjack\DataSources\Shape;
use ExeQue\Lumberjack\Exceptions\InvalidDataSourceResultException;

/** Points over time, at most Shape::MAX_POINTS. Shown on a chart. */
final readonly class SeriesResult implements DataSourceResult
{
    /** @var list<SeriesPoint> */
    public array $points;

    public function __construct(SeriesPoint ...$points)
    {
        if (count($points) > Shape::MAX_POINTS) {
            throw new InvalidDataSourceResultException('Expected at most '.Shape::MAX_POINTS.' points.');
        }

        $this->points = array_values($points);
    }

    public static function shape(): Shape
    {
        return Shape::Series;
    }

    /**
     * @return list<array{at: int, value: int|float|null}>
     */
    public function data(): array
    {
        return array_map(fn (SeriesPoint $point): array => ['at' => $point->at, 'value' => $point->value], $this->points);
    }
}
