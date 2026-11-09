<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ExeQue\Lumberjack\DataSources\CallbackDataSource;
use ExeQue\Lumberjack\DataSources\DataRange;
use ExeQue\Lumberjack\DataSources\DataSourceRegistry;
use ExeQue\Lumberjack\DataSources\Results\ListResult;
use ExeQue\Lumberjack\DataSources\Results\ListRow;
use ExeQue\Lumberjack\DataSources\Results\NumberResult;
use ExeQue\Lumberjack\DataSources\Results\SeriesPoint;
use ExeQue\Lumberjack\DataSources\Results\SeriesResult;
use ExeQue\Lumberjack\DataSources\Results\TableResult;
use ExeQue\Lumberjack\DataSources\Shape;
use ExeQue\Lumberjack\Testing\DataSources\AssertableColumn;
use ExeQue\Lumberjack\Testing\DataSources\AssertableList;
use ExeQue\Lumberjack\Testing\DataSources\AssertableSeries;
use ExeQue\Lumberjack\Testing\DataSources\AssertableTable;
use ExeQue\Lumberjack\Testing\InteractsWithDataSources;
use PHPUnit\Framework\AssertionFailedError;

uses(InteractsWithDataSources::class);

beforeEach(function () {
    $this->withConfig(['app.url' => 'https://app.test', 'lumberjack.data_sources.enabled' => true]);
    $sources = app(DataSourceRegistry::class);
    $sources->define('total', 'Total', fn (DataRange $range): NumberResult => new NumberResult(3), cacheFor: 300);
    $sources->define('hourly', 'Hourly', fn (DataRange $range): SeriesResult => new SeriesResult(
        new SeriesPoint(1000 * 3600, 1),
        new SeriesPoint(1001 * 3600, null),
        new SeriesPoint(1002 * 3600, 5),
    ));
    $sources->define('cities', 'Cities', fn (DataRange $range): ListResult => new ListResult(new ListRow('Oslo', 20), new ListRow('Bergen', 7)));
    $sources->define('report', 'Report', fn (DataRange $range): TableResult => new TableResult(['City', 'Orders'], [['Oslo', 20], ['Bergen', 7]]));
    $this->range = [CarbonImmutable::createFromTimestampUTC(1000 * 3600), CarbonImmutable::createFromTimestampUTC(1002 * 3600)];
});

describe('a number', function () {
    it('asserts its value, cache and registration', function () {
        $this->dataSource('total')->assertNumber(3)->assertCachedFor(300)->assertRegistered()->assertRowCount(1);
    });

    it('fails on another shape, and names an unknown key with what there is', function () {
        expect(fn () => $this->dataSource('hourly')->assertNumber(3))->toThrow(AssertionFailedError::class, 'series source')
            ->and(fn () => $this->dataSource('nope'))->toThrow(AssertionFailedError::class, 'Registered: total, hourly');
    });

    it('lets what a source throws reach the test', function () {
        app(DataSourceRegistry::class)->define('bad', 'Bad', fn (DataRange $range): NumberResult => throw new RuntimeException('boom'));

        expect(fn () => $this->dataSource('bad')->data())->toThrow(RuntimeException::class, 'boom');
    });
});

describe('a series', function () {
    it('asserts points, times and sums', function () {
        $this->dataSource('hourly')->between(...$this->range)->assertSeries(fn (AssertableSeries $series) => $series
            ->hasPoints(3)
            ->isChronological()
            ->isWithinRange()
            ->isEvery(hours: 1)
            ->startsAt(1000 * 3600)
            ->endsAt(gmdate('Y-m-d H:i:s', 1002 * 3600))
            ->coversRange()
            ->hasValueAt($this->range[0], 1)
            ->hasNoValueAt(1001 * 3600)
            ->hasNoPointAt(5)
            ->hasValues([1, null, 5])
            ->sumsTo(6)
            ->maxIs(5)
            ->minIs(1)
            ->averages(3)
            ->peaksAt($this->range[1])
            ->each(fn (SeriesPoint $point): bool => $point->value !== 9));
    });

    it('takes the whole series as an array', function () {
        $this->dataSource('hourly')->assertSeries([1000 * 3600 => 1, 1001 * 3600 => null, 1002 * 3600 => 5]);
    });

    it('fails showing the series with the wrong point marked', function () {
        expect(fn () => $this->dataSource('hourly')->assertSeries(fn (AssertableSeries $series) => $series->hasValueAt($this->range[1], 9)))
            ->toThrow(AssertionFailedError::class, '> '.gmdate('Y-m-d H:i:s', 1002 * 3600).'  5');
    });
});

describe('a list', function () {
    it('asserts rows, order and sums', function () {
        $this->dataSource('cities')->assertRows(fn (AssertableList $rows) => $rows
            ->hasRows(2)
            ->startsWith('Oslo')
            ->endsWith('Bergen')
            ->isSortedByValue(descending: true)
            ->hasRow('Bergen', 7)
            ->missingLabel('Tromsø')
            ->hasLabels(['Oslo'])
            ->sumsTo(27));
    });

    it('takes the whole list in order', function () {
        $this->dataSource('cities')->assertRows(['Oslo' => 20, 'Bergen' => 7]);

        expect(fn () => $this->dataSource('cities')->assertRows(['Bergen' => 7, 'Oslo' => 20]))->toThrow(AssertionFailedError::class);
    });
});

describe('a table', function () {
    it('asserts columns, rows and a column', function () {
        $this->dataSource('report')->assertTable(fn (AssertableTable $table) => $table
            ->hasColumns(['City', 'Orders'])
            ->hasRow(['Oslo', 20])
            ->missingRow(['Oslo', 21])
            ->hasRowWhere('City', 'Bergen', ['Orders' => 7])
            ->eachRow(fn (array $row): bool => $row['Orders'] > 0)
            ->column('Orders', fn (AssertableColumn $column) => $column->isNumeric()->isSorted(descending: true)->contains(7)->isUnique()->sumsTo(27)));
    });

    it('takes the whole table, and names the columns when one is unknown', function () {
        $this->dataSource('report')->assertTable(['City', 'Orders'], [['Oslo', 20], ['Bergen', 7]]);

        expect(fn () => $this->dataSource('report')->assertTable(fn (AssertableTable $table) => $table->column('Nope', fn () => null)))
            ->toThrow(AssertionFailedError::class, 'The columns are');
    });
});

describe('positions', function () {
    it('asserts a point, row or cell by its position, from either end', function () {
        $this->dataSource('hourly')->assertSeries(fn (AssertableSeries $series) => $series->pointIs(0, 1000 * 3600, 1)->valueIs(1, null)->timeIs(-1, 1002 * 3600));
        $this->dataSource('cities')->assertRows(fn (AssertableList $rows) => $rows->rowIs(0, 'Oslo', 20)->labelIs(-1, 'Bergen')->valueIs(1, 7));
        $this->dataSource('report')->assertTable(fn (AssertableTable $table) => $table->rowIs(-1, ['Bergen', 7])->cellIs(0, 'Orders', 20));
    });

    it('fails on a position with nothing there', function () {
        expect(fn () => $this->dataSource('cities')->assertRows(fn (AssertableList $rows) => $rows->rowIs(5, 'x', 1)))
            ->toThrow(AssertionFailedError::class, 'nothing at position 5');
    });
});

describe('what must not pass', function () {
    it('refuses', function (Closure $wrong) {
        expect($wrong)->toThrow(AssertionFailedError::class);
    })->with([
        'an unsorted column with a null' => [fn () => (new AssertableColumn('A', [0, null, -5]))->isSorted()],
        'a tiny value for zero' => [fn () => (new AssertableColumn('A', [0.0000005]))->contains(0)],
        'equal numbers as unique' => [fn () => (new AssertableColumn('A', [1, 1.0]))->isUnique()],
        'almost nothing for zero' => [fn () => (new AssertableColumn('A', [1e-10]))->contains(0)],
        'nearly equal integers' => [fn () => (new AssertableColumn('A', [9007199254740993]))->contains(9007199254740992)],
    ]);

    it('sums large floats, and takes any of two equal peaks', function () {
        (new AssertableColumn('A', [1e10 + 0.2, 1e10 + 0.2, 1e10 + 0.2]))->sumsTo(30000000000.6);
        $this->dataSource('hourly')->assertSeries(fn (AssertableSeries $series) => $series->peaksAt(1002 * 3600));
    });

    it('covers a range to within one step, and not from far outside', function () {
        $range = new DataRange(CarbonImmutable::createFromTimestampUTC(0), CarbonImmutable::createFromTimestampUTC(7200));

        (new AssertableSeries([new SeriesPoint(0, 1), new SeriesPoint(3600, 1)], $range))->coversRange();

        expect(fn () => (new AssertableSeries([new SeriesPoint(90000, 1), new SeriesPoint(93600, 1)], $range))->coversRange())->toThrow(AssertionFailedError::class);
    });
});

describe('pulling', function () {
    it('goes through the signed endpoint, also twice in a second and with no token set', function () {
        $this->dataSource('total')->pull()->assertOk()->assertJsonPath('sources.total.data', 3);
        $this->dataSource('total')->pull()->assertOk();
    });
});

describe('what the reviews found', function () {
    it('finds a numeric column by name', function () {
        app(DataSourceRegistry::class)->define('years', 'Years', fn (DataRange $range): TableResult => new TableResult(['City', '2024'], [['Oslo', 1]]));

        $this->dataSource('years')->assertTable(fn (AssertableTable $table) => $table->hasRowWhere('City', 'Oslo', ['2024' => 1]));
    });

    it('holds a small average to what it is', function () {
        app(DataSourceRegistry::class)->define('rate', 'Rate', fn (DataRange $range): SeriesResult => new SeriesResult(new SeriesPoint(3600, 0.009)));

        expect(fn () => $this->dataSource('rate')->assertSeries(fn (AssertableSeries $series) => $series->averages(0.001)))->toThrow(AssertionFailedError::class, 'average');
    });

    it('does not take another source with the same key as registered', function () {
        $other = new CallbackDataSource('total', 'Total', Shape::Number, fn (DataRange $range): NumberResult => new NumberResult(3));

        expect(fn () => $this->dataSource($other)->assertRegistered())->toThrow(AssertionFailedError::class, 'registered');
    });

    it('refuses a range the endpoint would refuse', function () {
        expect(fn () => $this->dataSource('total')->forLast())->toThrow(AssertionFailedError::class, 'never asks')
            ->and(fn () => $this->dataSource('total')->forLast(days: 401))->toThrow(AssertionFailedError::class, 'never asks');
    });

    it('refuses a key production would refuse', function () {
        $bad = new CallbackDataSource('Bad Key!', 'Bad', Shape::Number, fn (DataRange $range): NumberResult => new NumberResult(1));

        expect(fn () => $this->dataSource($bad))->toThrow(AssertionFailedError::class, 'refuses this key');
    });

    it('sorts text as text', function () {
        app(DataSourceRegistry::class)->define('labels', 'Labels', fn (DataRange $range): ListResult => new ListResult(new ListRow('10', 1), new ListRow('9', 2)));

        $this->dataSource('labels')->assertRows(fn (AssertableList $list) => $list->isSortedByLabel());
    });
});
