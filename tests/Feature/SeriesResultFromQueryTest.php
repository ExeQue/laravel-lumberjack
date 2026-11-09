<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ExeQue\Lumberjack\DataSources\DataRange;
use ExeQue\Lumberjack\DataSources\Results\SeriesResult;
use ExeQue\Lumberjack\Exceptions\InvalidDataSourceResultException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('series_rows', function (Blueprint $table): void {
        $table->id();
        $table->integer('cents')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('paid_at')->nullable();
    });

    DB::table('series_rows')->insert([
        ['cents' => 150, 'created_at' => '2026-01-01 00:00:00', 'paid_at' => '2026-01-01 02:00:00'],
        ['cents' => 250, 'created_at' => '2026-01-01 00:59:59', 'paid_at' => '2026-01-01 02:30:00'],
        ['cents' => 400, 'created_at' => '2026-01-01 02:00:00', 'paid_at' => '2026-01-01 00:10:00'],
        ['cents' => null, 'created_at' => '2026-01-01 02:30:00', 'paid_at' => null],
        ['cents' => 999, 'created_at' => '2026-01-01 03:00:00', 'paid_at' => '2026-01-01 03:00:00'],
    ]);

    $this->range = new DataRange(CarbonImmutable::parse('2026-01-01 00:00', 'UTC'), CarbonImmutable::parse('2026-01-01 03:00', 'UTC'), 3600);
    $this->values = fn (SeriesResult $result): array => array_column($result->data(), 'value');
});

it('counts rows per bucket by created_at, an empty bucket as 0 and the end left out', function () {
    expect(($this->values)(SeriesResult::count(DB::table('series_rows'), $this->range)))->toBe([2, 0, 2]);
});

it('counts by another column', function () {
    expect(($this->values)(SeriesResult::count(DB::table('series_rows'), $this->range, column: 'paid_at')))->toBe([1, 0, 2]);
});

it('adds up a column, nulls as nothing, divided when asked', function () {
    expect(($this->values)(SeriesResult::sum(DB::table('series_rows'), $this->range, 'cents')))->toBe([400, 0, 400])
        ->and(($this->values)(SeriesResult::sum(DB::table('series_rows'), $this->range, 'cents', column: 'paid_at', divideBy: 100)))->toBe([4, 0, 4])
        ->and(($this->values)(SeriesResult::sum(DB::table('series_rows'), $this->range, 'cents', divideBy: 8)))->toBe([50, 0, 50]);
});

it('takes an Eloquent query, keeping its conditions and ignoring its order and selects', function () {
    $model = new class () extends Model {
        protected $table = 'series_rows';
    };

    expect(($this->values)(SeriesResult::count($model->newQuery()->where('cents', '>', 200)->select('id')->orderByDesc('id'), $this->range)))->toBe([1, 0, 1]);
});

it('leaves the query it was given as it was', function (Closure $query) {
    $given = $query();
    SeriesResult::count($given, $this->range);

    expect($given->count())->toBe(5);
})->with([
    'a query builder' => fn () => DB::table('series_rows'),
    'an Eloquent query without scopes' => fn () => (new class () extends Model {
        protected $table = 'series_rows';
    })->newQuery(),
]);

it('keeps an orWhere inside the range', function () {
    DB::table('series_rows')->insert(['cents' => 7, 'created_at' => '2025-01-01 00:00:00']);

    $query = DB::table('series_rows')->where('cents', 7)->orWhere('cents', 150);

    expect(($this->values)(SeriesResult::count($query, $this->range)))->toBe([1, 0, 0]);
});

it('refuses a query it cannot read row by row', function (Closure $change) {
    expect(fn () => SeriesResult::count($change(DB::table('series_rows')), $this->range))->toThrow(InvalidDataSourceResultException::class);
})->with([
    'distinct' => fn ($q) => $q->distinct(),
    'group by' => fn ($q) => $q->groupBy('cents'),
    'having' => fn ($q) => $q->having('cents', '>', 1),
    'limit' => fn ($q) => $q->limit(1),
    'offset' => fn ($q) => $q->offset(1),
    'union' => fn ($q) => $q->union(DB::table('series_rows')),
]);

it('refuses a summed column without numbers', function () {
    DB::table('series_rows')->where('cents', 150)->update(['cents' => 'abc']);

    expect(fn () => SeriesResult::sum(DB::table('series_rows'), $this->range, 'cents'))->toThrow(InvalidDataSourceResultException::class);
});

it('reads stored times in the time zone of the app', function () {
    config(['app.timezone' => 'Europe/Copenhagen']);
    DB::table('series_rows')->delete();
    DB::table('series_rows')->insert(['created_at' => '2026-01-01 02:30:00']);

    expect(($this->values)(SeriesResult::count(DB::table('series_rows'), $this->range)))->toBe([0, 1, 0]);
});

it('has no points when no bucket begins in the range', function () {
    $range = new DataRange(CarbonImmutable::parse('2026-01-01 00:10', 'UTC'), CarbonImmutable::parse('2026-01-01 00:50', 'UTC'), 3600);

    expect(SeriesResult::count(DB::table('series_rows'), $range)->points)->toBe([]);
});

it('refuses a divisor that is no divisor', function () {
    expect(fn () => SeriesResult::sum(DB::table('series_rows'), $this->range, 'cents', divideBy: 0))->toThrow(InvalidDataSourceResultException::class);
});
