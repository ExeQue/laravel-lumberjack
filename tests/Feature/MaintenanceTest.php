<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\Testing\EventType;
use Illuminate\Foundation\Events\MaintenanceModeDisabled;
use Illuminate\Foundation\Events\MaintenanceModeEnabled;
use Illuminate\Http\Client\Factory as HttpFactory;
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

    it('sends no expected minutes for a value that is not a number', function () {
        $this->withConfig(['lumberjack.maintenance.expected_minutes' => 'soon']);
        Http::fake(['*' => Http::response(status: 202)]);

        event(new MaintenanceModeEnabled());

        expect(maintenanceBatches()[0]['events'][0]['expected_minutes'])->toBeNull();
    });

    it('follows a redirect to https with the POST and its body, as a proxy upgrading http answers', function () {
        $this->withConfig(['lumberjack.url' => 'http://lumberjack.test']);
        Http::fake([
            'http://lumberjack.test/*' => Http::response(status: 301, headers: ['Location' => 'https://lumberjack.test/api/ingest']),
            'https://lumberjack.test/*' => fn (Request $request) => Http::response(status: $request->method() === 'POST' && $request->body() !== '' ? 202 : 405),
        ]);

        event(new MaintenanceModeEnabled());

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://lumberjack.test/api/ingest' && $request->method() === 'POST');
        expect($this->sentEvents('maintenance'))->toBe([]);
    });

    it('does not follow a redirect to plain http, and hands the event to the agent', function () {
        Http::fake(['https://lumberjack.test/*' => Http::response(status: 301, headers: ['Location' => 'http://elsewhere.test/api/ingest'])]);

        event(new MaintenanceModeEnabled());

        Http::assertSentCount(1);
        expect($this->sentEvents('maintenance')[0])->toMatchArray(['state' => 'start']);
    });

    it('hands the event to the agent when Lumberjack cannot be reached', function () {
        Http::fake(['*' => Http::response('down', 503)]);

        event(new MaintenanceModeEnabled());

        expect($this->sentEvents('maintenance')[0])->toMatchArray(['state' => 'start', 'expected_minutes' => null]);
    });

    it('keeps a refusal from the agent too, as the agent would be refused', function () {
        Http::fake(['*' => Http::response(status: 422)]);

        event(new MaintenanceModeEnabled());

        Http::assertSentCount(1);
        expect($this->sentEvents('maintenance'))->toBe([]);
    });

    it('hands the event to the agent without a URL or token', function () {
        $this->withConfig(['lumberjack.token' => null]);
        Http::fake();

        event(new MaintenanceModeEnabled());

        Http::assertNothingSent();
        expect($this->sentEvents('maintenance')[0])->toMatchArray(['state' => 'start']);
    });

    it('sends nothing while faked, even with recording turned off, and records it for assertions', function () {
        $this->withConfig(['lumberjack.enabled' => false]);
        Http::fake();
        Lumberjack::fake();

        event(new MaintenanceModeEnabled());

        Http::assertNothingSent();
        Lumberjack::assertRecorded(EventType::Maintenance, ['state' => 'start']);
    });

    it('never breaks artisan down when sending itself throws', function () {
        app()->bind(HttpFactory::class, fn () => throw new RuntimeException('boom'));

        expect(fn () => event(new MaintenanceModeEnabled()))->not->toThrow(Throwable::class)
            ->and($this->sentEvents('maintenance')[0])->toMatchArray(['state' => 'start']);
    });

    it('never breaks artisan down or up, whatever sending does', function () {
        Http::fake(['*' => fn () => throw new RuntimeException('boom')]);

        // The events artisan down and up fire, not the commands themselves: a real down puts the
        // shared testbench app in maintenance, and parallel tests then get 503.
        expect(fn () => event(new MaintenanceModeEnabled()))->not->toThrow(Throwable::class)
            ->and(fn () => event(new MaintenanceModeDisabled()))->not->toThrow(Throwable::class);

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
