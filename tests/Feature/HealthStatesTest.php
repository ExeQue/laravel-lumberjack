<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Events\HealthStateChanged;
use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\Health\HealthState;
use ExeQue\Lumberjack\Health\HealthStates;
use ExeQue\LumberjackAgent\Agent;
use ExeQue\LumberjackAgent\Sender;
use ExeQue\LumberjackAgent\Spool;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->answer = fn (string $state = 'down', string $environment = 'testing'): array => ['checks' => [
        ['slug' => 'disk:s3', 'environment' => $environment, 'state' => $state, 'since' => '2026-11-03T09:12:00+00:00', 'message' => 'No space'],
    ], 'at' => '2026-11-03T09:15:00+00:00'];
});

describe('reading states', function () {
    it('reads what is stored without asking Lumberjack within the ttl', function () {
        Http::fake();
        app(HealthStates::class)->fill(($this->answer)());

        $handle = Lumberjack::health('disk:s3');

        expect($handle->state())->toBe(HealthState::Down)
            ->and($handle->isHealthy())->toBeFalse()
            ->and($handle->state()->isBad())->toBeTrue()
            ->and($handle->since()?->toIso8601String())->toBe('2026-11-03T09:12:00+00:00')
            ->and($handle->message())->toBe('No space')
            ->and(array_keys(Lumberjack::healthStates()))->toBe(['disk:s3']);
        Http::assertNothingSent();
    });

    it('works for a slug that is not registered, and is unknown for one Lumberjack does not list', function () {
        Http::fake();
        app(HealthStates::class)->fill(($this->answer)('ok'));

        expect(Lumberjack::health('disk:s3')->isHealthy())->toBeTrue()
            ->and(Lumberjack::health('nope')->state())->toBe(HealthState::Unknown)
            ->and(Lumberjack::health('nope')->since())->toBeNull();
    });

    it('keeps the checks of its own environment from an answer that lists them all', function () {
        app(HealthStates::class)->fill(['checks' => [
            ['slug' => 'a', 'environment' => 'production', 'state' => 'down'],
            ['slug' => 'b', 'environment' => 'testing', 'state' => 'ok'],
            ['slug' => 'c', 'environment' => null, 'state' => 'ok'],
        ]]);

        expect(array_keys(app(HealthStates::class)->all()))->toBe(['b', 'c']);
    });
});

describe('the memo', function () {
    it('reads the cache once while asked many times within the memo, and again after it', function () {
        app(HealthStates::class)->fill(($this->answer)('down'));
        $states = app(HealthStates::class);

        cache()->forever(HealthStates::KEY, ['fetched_at' => now()->getTimestamp(), 'checks' => ['disk:s3' => ['state' => 'ok']]]);

        expect($states->get('disk:s3'))->toBe(HealthState::Down);

        $this->travel(6)->seconds();

        expect($states->get('disk:s3'))->toBe(HealthState::Ok);
    });

    it('reads every time with a memo of 0', function () {
        config(['lumberjack.health.states.memo' => 0]);
        app()->forgetInstance(HealthStates::class);
        $states = app(HealthStates::class);
        $states->fill(($this->answer)('down'));

        cache()->forever(HealthStates::KEY, ['fetched_at' => now()->getTimestamp(), 'checks' => ['disk:s3' => ['state' => 'ok']]]);

        expect($states->get('disk:s3'))->toBe(HealthState::Ok);
    });
});

describe('pulling', function () {
    it('asks GET /api/health with the token once the stored states are older than the ttl, then not again within it', function () {
        Http::fake(['*/api/health*' => Http::response(($this->answer)('degraded'))]);

        expect(Lumberjack::health('disk:s3')->state())->toBe(HealthState::Degraded)
            ->and(Lumberjack::health('disk:s3')->state())->toBe(HealthState::Degraded);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://lumberjack.test/api/health?environment=testing' && $r->hasHeader('Authorization', 'Bearer secret-token'));

        $this->travel(31)->seconds();
        Lumberjack::healthStates();
        Http::assertSentCount(2);
    });

    it('returns what is cached, or unknown, when Lumberjack fails, and does not ask again within the ttl', function () {
        Http::fake(['*' => Http::response('down', 503)]);

        expect(Lumberjack::health('disk:s3')->state())->toBe(HealthState::Unknown)
            ->and(Lumberjack::health('disk:s3')->state())->toBe(HealthState::Unknown);
        Http::assertSentCount(1);

        app(HealthStates::class)->fill(($this->answer)());
        $this->travel(31)->seconds();
        Http::fake(['*' => fn () => throw new RuntimeException('timeout')]);

        expect(Lumberjack::health('disk:s3')->state())->toBe(HealthState::Down)
            ->and(Lumberjack::health('disk:s3')->state())->toBe(HealthState::Down);
    });

    it('never throws when its cache store does not work', function () {
        $states = new HealthStates(app('cache'), app(Factory::class), app('events'), 'https://lumberjack.test', 'secret-token', 'testing', 'missing-store');

        expect($states->get('disk:s3'))->toBe(HealthState::Unknown)->and($states->all())->toBe([]);
    });

    it('leaves the states to the agent when pull is off', function () {
        Http::fake();
        $this->withConfig(['lumberjack.health.states.pull' => false]);

        expect(Lumberjack::health('disk:s3')->state())->toBe(HealthState::Unknown);
        Http::assertNothingSent();
    });
});

describe('the agent', function () {
    it('writes the health states its heartbeat gets back into the store, and sends its environment', function () {
        Http::fake(['*/heartbeat' => Http::response(['ok' => true, 'health' => ($this->answer)()])]);
        $states = app(HealthStates::class);
        $agent = new Agent(
            new Sender('https://lumberjack.test', 'secret-token', http: new GuzzleHttp\Client(['handler' => app(Factory::class)->createPendingRequest()->buildHandlerStack()])),
            new Spool(sys_get_temp_dir().'/lumberjack-spool-'.bin2hex(random_bytes(4)), 10_000),
            5,
            'web-1',
            status: fn (): array => ['environment' => 'testing'],
            health: fn (array $health) => $states->fill($health),
        );

        $agent->tick(microtime(true));

        expect($states->get('disk:s3'))->toBe(HealthState::Down);
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/heartbeat') && $r['environment'] === 'testing');
    });

    it('carries on when the store fails', function () {
        Http::fake(['*/heartbeat' => Http::response(['ok' => true, 'health' => ['checks' => []]])]);
        $agent = new Agent(
            new Sender('https://lumberjack.test', 'secret-token', http: new GuzzleHttp\Client(['handler' => app(Factory::class)->createPendingRequest()->buildHandlerStack()])),
            new Spool(sys_get_temp_dir().'/lumberjack-spool-'.bin2hex(random_bytes(4)), 10_000),
            health: fn () => throw new RuntimeException('cache down'),
        );

        $agent->tick(microtime(true));
    })->throwsNoExceptions();
});

describe('HealthStateChanged', function () {
    it('is dispatched for a new slug from unknown, on a change and when a slug goes away, not when nothing changed', function () {
        Event::fake([HealthStateChanged::class]);
        $states = app(HealthStates::class);

        $states->fill(($this->answer)('ok'));
        $states->fill(($this->answer)('ok'));
        $states->fill(($this->answer)('down'));
        $states->fill(['checks' => []]);

        $changes = Event::dispatched(HealthStateChanged::class)->map(fn (array $e): array => [$e[0]->slug, $e[0]->from, $e[0]->to])->all();
        expect($changes)->toBe([
            ['disk:s3', HealthState::Unknown, HealthState::Ok],
            ['disk:s3', HealthState::Ok, HealthState::Down],
            ['disk:s3', HealthState::Down, HealthState::Unknown],
        ]);
        Event::assertDispatched(HealthStateChanged::class, fn (HealthStateChanged $e): bool => $e->to === HealthState::Down && $e->snapshot->message === 'No space');
    });

    it('is dispatched by a pull', function () {
        Event::fake([HealthStateChanged::class]);
        Http::fake(['*/api/health*' => Http::response(($this->answer)())]);

        Lumberjack::healthStates();

        Event::assertDispatched(HealthStateChanged::class, fn (HealthStateChanged $e): bool => $e->from === HealthState::Unknown && $e->to === HealthState::Down);
    });
});

describe('in the request path', function () {
    it('does not pull while the agent keeps the states fresh', function () {
        Http::fake();
        app(HealthStates::class)->fill(($this->answer)('ok'), fromAgent: true);
        $this->travel(90)->seconds();

        expect(Lumberjack::health('disk:s3')->state())->toBe(HealthState::Ok);
        Http::assertNothingSent();
    });

    it('serves the stale states at once and pulls after the response', function () {
        Http::fake(['*/api/health*' => Http::response(($this->answer)('down'))]);
        app(HealthStates::class)->fill(($this->answer)('ok'));
        $this->travel(31)->seconds();
        Event::fake([HealthStateChanged::class]);
        $later = [];
        $states = new HealthStates(app('cache'), app(Factory::class), app('events'), 'https://lumberjack.test', 'secret-token', 'testing', memo: 0, later: function (Closure $pull) use (&$later): void {
            $later[] = $pull;
        });

        expect($states->get('disk:s3'))->toBe(HealthState::Ok)
            ->and($later)->toHaveCount(1);
        Http::assertNothingSent();
        Event::assertNotDispatched(HealthStateChanged::class);

        // The app terminates.
        $later[0]();

        expect($states->get('disk:s3'))->toBe(HealthState::Down);
        Event::assertDispatched(HealthStateChanged::class);
    });

});
