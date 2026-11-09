<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing\DataSources;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use ExeQue\Lumberjack\Contracts\DataSource;
use ExeQue\Lumberjack\Contracts\DataSourceResult;
use ExeQue\Lumberjack\DataSources\CallbackDataSource;
use ExeQue\Lumberjack\DataSources\DataRange;
use ExeQue\Lumberjack\DataSources\DataSourceRegistry;
use ExeQue\Lumberjack\DataSources\DataSourceResolver;
use ExeQue\Lumberjack\DataSources\Http\DataSourcesController;
use ExeQue\Lumberjack\DataSources\Results\Cell;
use ExeQue\Lumberjack\DataSources\Results\ListResult;
use ExeQue\Lumberjack\DataSources\Results\NumberResult;
use ExeQue\Lumberjack\DataSources\Results\SeriesResult;
use ExeQue\Lumberjack\DataSources\Results\TableResult;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * One data source under test. It calls resolve() itself, once per range and past any
 * cache, so what a source throws reaches the test and an old answer cannot pass it.
 */
final class TestedDataSource
{
    private DataRange $range;

    private ?DataSourceResult $result = null;

    /**
     * @param  Closure(string, DataRange): TestResponse<\Symfony\Component\HttpFoundation\Response>  $pull
     */
    public function __construct(
        private readonly DataSource $source,
        private readonly DataSourceRegistry $registry,
        private readonly Closure $pull,
    ) {
        $this->forLast(hours: 24);
    }

    public function forLast(int $days = 0, int $hours = 0, int $minutes = 0): self
    {
        $to = CarbonImmutable::now('UTC');

        return $this->over(new DataRange($to->subDays($days)->subHours($hours)->subMinutes($minutes), $to));
    }

    public function between(DateTimeInterface $from, DateTimeInterface $to): self
    {
        return $this->over(new DataRange(CarbonImmutable::instance($from)->utc(), CarbonImmutable::instance($to)->utc()));
    }

    public function result(): DataSourceResult
    {
        return $this->result ??= $this->source->resolve($this->range);
    }

    public function data(): mixed
    {
        return $this->result()->data();
    }

    public function assertNumber(int|float|null $expected): self
    {
        $result = $this->resultOf(NumberResult::class);

        Assert::assertTrue(Cell::equal($result->value, $expected), 'Expected '.json_encode($expected).', got '.json_encode($result->value).'.');

        return $this;
    }

    /**
     * @param  (Closure(AssertableSeries): mixed)|array<int|string, int|float|null>  $expected  time => value for the whole series
     */
    public function assertSeries(Closure|array $expected): self
    {
        $series = new AssertableSeries($this->resultOf(SeriesResult::class)->points, $this->range);
        $expected instanceof Closure ? $expected($series) : $series->is($expected);

        return $this;
    }

    /**
     * @param  (Closure(AssertableList): mixed)|array<int|string, int|float|string|null>  $expected  label => value for the whole list
     */
    public function assertRows(Closure|array $expected): self
    {
        $list = new AssertableList($this->resultOf(ListResult::class)->rows);
        $expected instanceof Closure ? $expected($list) : $list->is($expected);

        return $this;
    }

    /**
     * @param  (Closure(AssertableTable): mixed)|list<string>  $expected  the columns, with $rows for the whole table
     * @param  list<list<int|float|string|null>>  $rows
     */
    public function assertTable(Closure|array $expected, array $rows = []): self
    {
        $result = $this->resultOf(TableResult::class);
        $table = new AssertableTable($result->columns, $result->rows);
        $expected instanceof Closure ? $expected($table) : $table->is($expected, $rows);

        return $this;
    }

    /** No value, points or rows, whatever the shape. */
    public function assertEmpty(): self
    {
        Assert::assertSame(0, $this->count(), 'Expected the data source to be empty.');

        return $this;
    }

    public function assertRowCount(int $expected): self
    {
        Assert::assertSame($expected, $this->count(), 'Expected '.$expected.' points or rows.');

        return $this;
    }

    public function assertCachedFor(int $seconds): self
    {
        Assert::assertSame($seconds, DataSourceResolver::cacheSeconds($this->source), 'Expected the source to declare a cache of '.$seconds.' seconds.');

        return $this;
    }

    public function assertNotCached(): self
    {
        Assert::assertNull(DataSourceResolver::cacheSeconds($this->source), 'Expected the source to declare no cache.');

        return $this;
    }

    public function assertRegistered(): self
    {
        $registered = $this->registry->all()[$this->source->key()] ?? null;

        Assert::assertTrue(
            $registered === $this->source || ($registered !== null && ! $this->source instanceof CallbackDataSource && $registered::class === $this->source::class),
            'Expected the source to be registered, so that Lumberjack is told of it.',
        );

        return $this;
    }

    /**
     * The source through the real, signed endpoint, with the app's middleware.
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    public function pull(): TestResponse
    {
        return ($this->pull)($this->source->key(), $this->range);
    }

    private function over(DataRange $range): self
    {
        Assert::assertTrue(
            $range->from < $range->to && $range->to->getTimestamp() - $range->from->getTimestamp() <= DataSourcesController::MAX_RANGE_SECONDS,
            'Lumberjack never asks for this range: it has to run forward and be at most 400 days.',
        );

        $this->range = $range;
        $this->result = null;

        return $this;
    }

    /**
     * @template T of DataSourceResult
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function resultOf(string $class): DataSourceResult
    {
        $result = $this->result();

        return $result instanceof $class ? $result : Assert::fail('Expected a '.$class::shape()->value.' source, but ['.$this->source->key().'] is a '.$result::shape()->value.' source.');
    }

    private function count(): int
    {
        $data = $this->result();

        return match (true) {
            $data instanceof NumberResult => $data->value === null ? 0 : 1,
            $data instanceof SeriesResult => count($data->points),
            $data instanceof ListResult, $data instanceof TableResult => count($data->rows),
            default => Assert::fail('Unknown result.'),
        };
    }
}
