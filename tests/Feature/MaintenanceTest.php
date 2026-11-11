<?php

declare(strict_types=1);

use Illuminate\Foundation\Events\MaintenanceModeDisabled;
use Illuminate\Foundation\Events\MaintenanceModeEnabled;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @return list<array<string, mixed>>
 */
function maintenanceBatches(): array
{
    return Http::recorded()->map(fn (array $pair): array => json_decode((string) gzdecode($pair[0]->body()), true))->all();
}

describe('maintenance mode', function () {
    it('sends start and end straight to Lumberjack, not through the agent', function () {
        $this->withConfig(['lumberjack.maintenance.expected_minutes' => '15']);
        Http::fake(['https://lumberjack.test/api/ingest' => Http::response(status: 202)]);

        event(new MaintenanceModeEnabled());
        event(new MaintenanceModeDisabled());

        [$down, $up] = maintenanceBatches();

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret-token'));
        expect($down)->toMatchArray(['environment' => 'testing', 'release' => 'abc123'])
            ->and($down['events'][0])->toMatchArray(['type' => 'maintenance', 'state' => 'start', 'message' => null, 'expected_minutes' => 15])
            ->and($up['events'][0])->toMatchArray(['type' => 'maintenance', 'state' => 'end'])
            ->and($up['events'][0])->not->toHaveKey('expected_minutes')
            ->and($this->sentEvents('maintenance'))->toBe([]);
    });

    it('hands the event to the agent when Lumberjack cannot be reached', function () {
        Http::fake(['*' => Http::response('down', 503)]);

        event(new MaintenanceModeEnabled());

        expect($this->sentEvents('maintenance')[0])->toMatchArray(['state' => 'start', 'expected_minutes' => null]);
    });

    it('never breaks artisan down or up, whatever sending does', function () {
        Http::fake(['*' => fn () => throw new RuntimeException('boom')]);

        $this->artisan('down')->assertExitCode(0);
        $this->artisan('up')->assertExitCode(0);

        expect(array_column($this->sentEvents('maintenance'), 'state'))->toBe(['start', 'end']);
    });

    it('sends nothing when turned off', function () {
        $this->withConfig(['lumberjack.maintenance.enabled' => false]);
        Http::fake();

        event(new MaintenanceModeEnabled());

        Http::assertNothingSent();
        expect($this->sentEvents('maintenance'))->toBe([]);
    });
});
