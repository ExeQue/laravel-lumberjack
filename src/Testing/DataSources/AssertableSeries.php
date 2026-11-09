<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing\DataSources;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use ExeQue\Lumberjack\DataSources\DataRange;
use ExeQue\Lumberjack\DataSources\Results\SeriesPoint;
use ExeQue\Lumberjack\Testing\DataSources\Concerns\ComparesResults;
use ExeQue\Lumberjack\Testing\DataSources\Concerns\SumsNumbers;

/**
 * Assertions on a series. Times are a date, a Unix time or a string read as UTC, and are
 * compared as moments, so a timezone cannot fail a test.
 */
final class AssertableSeries
{
    use ComparesResults;
    use SumsNumbers;

    /**
     * @param  list<SeriesPoint>  $points
     */
    public function __construct(
        private readonly array $points,
        private readonly DataRange $range,
    ) {
    }

    public function hasPoints(int $expected): static
    {
        return $this->check(count($this->points) === $expected, 'Expected '.$expected.' points, got '.count($this->points).'.');
    }

    public function isEmpty(): static
    {
        return $this->hasPoints(0);
    }

    public function isChronological(): static
    {
        $wrong = $this->outOfOrder($this->times(), false);

        return $this->check($wrong === null, 'Expected the points in order of time.', $wrong);
    }

    public function isWithinRange(): static
    {
        $from = $this->range->from->getTimestamp();
        $to = $this->range->to->getTimestamp();

        foreach ($this->times() as $i => $at) {
            $this->check($at >= $from && $at <= $to, 'Expected every point between '.$this->time($from).' and '.$this->time($to).'.', $i);
        }

        return $this;
    }

    public function isEvery(int $days = 0, int $hours = 0, int $minutes = 0, int $seconds = 0): static
    {
        $step = $days * 86400 + $hours * 3600 + $minutes * 60 + $seconds;
        $times = $this->times();

        for ($i = 1, $count = count($times); $i < $count; $i++) {
            $this->check($times[$i] - $times[$i - 1] === $step, 'Expected a point every '.$step.' seconds, got '.($times[$i] - $times[$i - 1]).'.', $i);
        }

        return $this;
    }

    public function startsAt(DateTimeInterface|int|string $at): static
    {
        return $this->check(($this->times()[0] ?? null) === self::moment($at), 'Expected the first point at '.$this->time(self::moment($at)).'.', 0);
    }

    public function endsAt(DateTimeInterface|int|string $at): static
    {
        $last = count($this->points) - 1;

        return $this->check(($this->times()[$last] ?? null) === self::moment($at), 'Expected the last point at '.$this->time(self::moment($at)).'.', $last);
    }

    /** The points reach both ends of the range, to within one step between points; a single point is one step as long as the range. */
    public function coversRange(): static
    {
        $times = $this->times();
        $step = count($times) > 1 ? $times[1] - $times[0] : $this->range->to->getTimestamp() - $this->range->from->getTimestamp();
        $within = fn (int $distance): bool => $distance >= 0 && $distance <= $step;
        $covered = $times !== [] && $times[0] - $this->range->from->getTimestamp() > -max($step, 1) && $times[0] - $this->range->from->getTimestamp() <= $step && $within($this->range->to->getTimestamp() - $times[count($times) - 1]);

        return $this->check($covered, 'Expected the points to reach both ends of the range.');
    }

    public function hasPointAt(DateTimeInterface|int|string $at): static
    {
        return $this->check($this->indexAt($at) !== null, 'Expected a point at '.$this->time(self::moment($at)).'.');
    }

    public function hasNoPointAt(DateTimeInterface|int|string $at): static
    {
        return $this->check($this->indexAt($at) === null, 'Expected no point at '.$this->time(self::moment($at)).'.', $this->indexAt($at));
    }

    public function hasValueAt(DateTimeInterface|int|string $at, int|float $expected): static
    {
        $i = $this->hasPointAt($at)->indexAt($at);

        return $this->check($this->same($this->points[(int) $i]->value, $expected), 'Expected '.$expected.' at '.$this->time(self::moment($at)).', got '.$this->show($this->points[(int) $i]->value).'.', $i);
    }

    /** A point is there, on purpose without a value: a gap the chart shows. */
    public function hasNoValueAt(DateTimeInterface|int|string $at): static
    {
        $i = $this->hasPointAt($at)->indexAt($at);

        return $this->check($this->points[(int) $i]->value === null, 'Expected no value at '.$this->time(self::moment($at)).'.', $i);
    }

    /**
     * @param  list<int|float|null>  $expected
     */
    public function hasValues(array $expected): static
    {
        $this->hasPoints(count($expected));

        foreach ($expected as $i => $value) {
            $this->check($this->same($this->points[$i]->value, $value), 'Expected '.$this->show($value).' at position '.$i.', got '.$this->show($this->points[$i]->value).'.', $i);
        }

        return $this;
    }

    /** The point at this position has this time and value. A negative position counts from the end. */
    public function pointIs(int $index, DateTimeInterface|int|string $at, int|float|null $value): static
    {
        $i = $this->positionOf($index, count($this->points));

        return $this->check($this->points[$i]->at === self::moment($at) && $this->same($this->points[$i]->value, $value), 'Expected '.$this->time(self::moment($at)).' with '.$this->show($value).' at position '.$index.'.', $i);
    }

    public function timeIs(int $index, DateTimeInterface|int|string $at): static
    {
        $i = $this->positionOf($index, count($this->points));

        return $this->check($this->points[$i]->at === self::moment($at), 'Expected '.$this->time(self::moment($at)).' at position '.$index.'.', $i);
    }

    public function valueIs(int $index, int|float|null $value): static
    {
        $i = $this->positionOf($index, count($this->points));

        return $this->check($this->same($this->points[$i]->value, $value), 'Expected '.$this->show($value).' at position '.$index.', got '.$this->show($this->points[$i]->value).'.', $i);
    }

    /**
     * The whole series, as time => value.
     *
     * @param  array<int|string, int|float|null>  $expected
     */
    public function is(array $expected): static
    {
        $this->hasPoints(count($expected));

        foreach (array_keys($expected) as $i => $at) {
            $this->check($this->times()[$i] === self::moment($at), 'Expected the point at '.$this->time(self::moment($at)).' here.', $i);
        }

        return $this->hasValues(array_values($expected));
    }

    public function peaksAt(DateTimeInterface|int|string $at): static
    {
        $numbers = $this->numbers();
        $i = $this->indexAt($at);

        return $this->check($i !== null && $numbers !== [] && $this->points[$i]->value !== null && $this->same($this->points[$i]->value, max($numbers)), 'Expected the peak at '.$this->time(self::moment($at)).'.', $i);
    }

    public function averages(int|float $expected, ?float $delta = null): static
    {
        $numbers = $this->numbers();
        $average = $numbers === [] ? null : array_sum($numbers) / count($numbers);

        return $this->check($average !== null && ($delta === null ? $this->same($average, $expected) : abs($average - $expected) <= $delta), 'Expected an average of '.$expected.', got '.$this->show($average).'.');
    }

    /**
     * @param  Closure(SeriesPoint): bool  $check
     */
    public function each(Closure $check): static
    {
        foreach ($this->points as $i => $point) {
            $this->check($check($point) === true, 'A point did not pass the check.', $i);
        }

        return $this;
    }

    /**
     * @return list<SeriesPoint>
     */
    public function points(): array
    {
        return $this->points;
    }

    protected function lines(): array
    {
        return array_map(fn (SeriesPoint $point): string => $this->time($point->at).'  '.$this->show($point->value), $this->points);
    }

    protected function numbers(): array
    {
        return array_values(array_filter(array_map(fn (SeriesPoint $point): int|float|null => $point->value, $this->points), fn (int|float|null $value): bool => $value !== null));
    }

    /**
     * @return list<int>
     */
    private function times(): array
    {
        return array_map(fn (SeriesPoint $point): int => $point->at, $this->points);
    }

    private function indexAt(DateTimeInterface|int|string $at): ?int
    {
        $i = array_search(self::moment($at), $this->times(), true);

        return $i === false ? null : $i;
    }

    private static function moment(DateTimeInterface|int|string $at): int
    {
        return match (true) {
            $at instanceof DateTimeInterface => $at->getTimestamp(),
            is_int($at) => $at,
            default => CarbonImmutable::parse($at, 'UTC')->getTimestamp(),
        };
    }

    private function time(int $at): string
    {
        return gmdate('Y-m-d H:i:s', $at);
    }
}
