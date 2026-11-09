<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources\Results;

use Carbon\CarbonImmutable;
use ExeQue\Lumberjack\Contracts\DataSourceResult;
use ExeQue\Lumberjack\DataSources\DataRange;
use ExeQue\Lumberjack\DataSources\Shape;
use ExeQue\Lumberjack\Exceptions\InvalidDataSourceResultException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

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

    /**
     * How many rows fall in each bucket Lumberjack asked for, empty buckets as 0.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     * @param  string  $column  a datetime column in the app's time zone, qualified on a join
     */
    public static function count(EloquentBuilder|QueryBuilder $query, DataRange $range, string $column = 'created_at'): self
    {
        return self::tally($query, $range, $column, null, 1);
    }

    /**
     * A column added up per bucket, empty buckets as 0.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     * @param  string  $sum  a column name; a null counts as nothing
     * @param  string  $column  a datetime column in the app's time zone, qualified on a join
     * @param  int|float  $divideBy  for an amount stored in cents, 100
     */
    public static function sum(EloquentBuilder|QueryBuilder $query, DataRange $range, string $sum, string $column = 'created_at', int|float $divideBy = 1): self
    {
        if (! is_finite($divideBy) || $divideBy <= 0) {
            throw new InvalidDataSourceResultException('Expected a divisor above 0.');
        }

        return self::tally($query, $range, $column, $sum, $divideBy);
    }

    /**
     * The rows are read once and in time order, as a chart can ask for many buckets. A
     * database stores times without a zone: they are read in the app's.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     */
    private static function tally(EloquentBuilder|QueryBuilder $query, DataRange $range, string $column, ?string $sum, int|float $divideBy): self
    {
        $buckets = $range->buckets();

        if ($buckets === []) {
            return new self();
        }

        if (count($buckets) > Shape::MAX_POINTS) {
            throw new InvalidDataSourceResultException('Expected at most '.Shape::MAX_POINTS.' points.');
        }

        $base = $query instanceof EloquentBuilder ? (clone $query)->toBase() : clone $query;

        if ($base->distinct !== false || $base->groups !== null || $base->havings !== null || $base->limit !== null || $base->offset !== null || $base->unions !== null) {
            throw new InvalidDataSourceResultException('A series reads every row of a plain query: leave out distinct, group by, having, limit, offset and union.');
        }

        // Its own conditions as one, so that an orWhere cannot escape the range.
        $conditions = $base->forNestedWhere();
        $conditions->wheres = $base->wheres;
        $conditions->bindings['where'] = $base->bindings['where'];
        $base->wheres = [];
        $base->bindings['where'] = [];

        if ($conditions->wheres !== []) {
            $base->addNestedWhereQuery($conditions);
        }

        $first = $buckets[0]['from'];
        $last = $buckets[array_key_last($buckets)]['to'];
        $storedIn = (string) config('app.timezone');
        $rows = $base
            ->where($column, '>=', $first->setTimezone($storedIn))
            ->where($column, '<', $last->setTimezone($storedIn))
            ->reorder($column)
            ->select(array_filter([$column.' as lumberjack_at', $sum === null ? null : $sum.' as lumberjack_sum']))
            ->cursor();

        $totals = array_fill(0, count($buckets), 0);
        $bucket = 0;

        foreach ($rows as $row) {
            $at = CarbonImmutable::parse($row->lumberjack_at, $storedIn);

            // A column that keeps its own zone can answer just outside.
            if ($at < $first || $at >= $last) {
                continue;
            }

            while ($at >= $buckets[$bucket]['to'] && isset($buckets[$bucket + 1])) {
                $bucket++;
            }

            $value = $sum === null ? 1 : ($row->lumberjack_sum ?? 0);

            if (! is_numeric($value)) {
                throw new InvalidDataSourceResultException('Expected the summed column to hold numbers.');
            }

            $totals[$bucket] += $value + 0;
        }

        return new self(...array_map(
            fn (array $bucket, int|float $total): SeriesPoint => new SeriesPoint($bucket['from'], $divideBy === 1 ? $total : $total / $divideBy),
            $buckets,
            $totals,
        ));
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
