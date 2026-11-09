<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ExeQue\Lumberjack\DataSources\DataRange;

$starts = fn (string $from, string $to, int $bucket, string $timezone): array => array_map(
    fn (array $b): string => $b['from']->setTimezone($timezone)->format('m-d H:i'),
    (new DataRange(CarbonImmutable::parse($from, 'UTC'), CarbonImmutable::parse($to, 'UTC'), $bucket, $timezone))->buckets(),
);

it('starts days at local midnight, leaving out the one begun before the range', function () use ($starts) {
    expect($starts('2026-10-24 12:00', '2026-10-26 12:00', 86400, 'Europe/Copenhagen'))->toBe(['10-25 00:00', '10-26 00:00']);
});

it('makes a daylight saving day one bucket, in UTC and ending where the next begins', function () {
    $buckets = (new DataRange(CarbonImmutable::parse('2026-10-24 21:00', 'UTC'), CarbonImmutable::parse('2026-10-26 12:00', 'UTC'), 86400, 'Europe/Copenhagen'))->buckets();

    expect($buckets[0]['from']->toIso8601String())->toBe('2026-10-24T22:00:00+00:00')
        ->and($buckets[0]['to']->toIso8601String())->toBe('2026-10-25T23:00:00+00:00')
        ->and($buckets[1]['to']->toIso8601String())->toBe('2026-10-26T12:00:00+00:00');
});

it('keeps shorter buckets on the local clock across a daylight saving change', function () use ($starts) {
    expect($starts('2026-03-28 17:00', '2026-03-30 06:00', 21600, 'Europe/Copenhagen'))
        ->toBe(['03-28 18:00', '03-29 00:00', '03-29 06:00', '03-29 12:00', '03-29 18:00', '03-30 00:00', '03-30 06:00']);
});

it('does not repeat a moment the clock jumped over', function () use ($starts) {
    expect($starts('2026-03-29 00:30', '2026-03-29 02:00', 1800, 'Europe/Copenhagen'))->toBe(['03-29 01:30', '03-29 03:00', '03-29 03:30']);
});

it('goes back to midnight after a day whose midnight was skipped', function () use ($starts) {
    expect($starts('2026-03-07 05:00', '2026-03-10 05:00', 86400, 'America/Havana'))->toBe(['03-07 00:00', '03-08 01:00', '03-09 00:00', '03-10 00:00']);
});

it('has no buckets when none begins inside the range', function () use ($starts) {
    expect($starts('2026-01-01 10:10', '2026-01-01 10:50', 3600, 'UTC'))->toBe([]);
});

it('refuses a bucket that does not divide a day', function (int $bucket) {
    expect(fn () => (new DataRange(CarbonImmutable::parse('2026-01-01', 'UTC'), CarbonImmutable::parse('2026-01-02', 'UTC'), $bucket))->buckets())->toThrow(InvalidArgumentException::class);
})->with([0, -60, 25200]);
