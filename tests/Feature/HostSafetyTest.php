<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Health\Checks\CacheHealthCheck;
use ExeQue\Lumberjack\Health\Checks\DiskHealthCheck;
use ExeQue\Lumberjack\Health\HealthRegistry;
use ExeQue\Lumberjack\Recorder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

describe('turned off', function () {
    beforeEach(fn () => $this->withConfig([
        'lumberjack.enabled' => false,
        'lumberjack.health.checks' => [CacheHealthCheck::class],
        'lumberjack.webhook.enabled' => true,
        'lumberjack.webhook.secret' => 'whsec_test',
        'logging.channels.lumberjack' => ['driver' => 'lumberjack'],
    ]));

    it('renders no script, schedules no checks and listens to nothing', function () {
        Route::get('/page', fn () => 'ok');

        expect(app()->resolved(Recorder::class))->toBeFalse()
            ->and(Blade::render('@lumberjack'))->toBe('')
            ->and(array_filter(app(Schedule::class)->events(), fn ($e): bool => str_contains((string) $e->command, 'lumberjack:health')))->toBe([])
            ->and(Event::hasListeners(RouteMatched::class))->toBeFalse();

        $this->get('/page')->assertOk();
        $this->post('/lumberjack/webhook')->assertNotFound();
        Log::channel('lumberjack')->info('dropped');
        report(new RuntimeException('dropped'));
        app()->terminate();

        expect($this->agent->payloads)->toBe([]);
    });

    it('keeps its commands, which say so and do nothing', function () {
        $this->artisan('lumberjack:health')->expectsOutputToContain('Lumberjack is disabled')->assertSuccessful();

        expect($this->agent->payloads)->toBe([]);
    });
});

describe('a mistake in Lumberjack\'s config never breaks the app', function () {
    it('skips a health check with a bad slug, and the app\'s own schedule still runs', function () {
        $this->withConfig(['lumberjack.health.checks' => [[DiskHealthCheck::class, 'disk' => 'local', 'slug' => 'Bad Slug!'], CacheHealthCheck::class]]);
        app(Schedule::class)->command('reports:send')->hourly();

        $commands = array_map(fn ($e): string => (string) $e->command, app(Schedule::class)->events());

        expect($commands)->toHaveCount(2)
            ->and(array_keys(app(HealthRegistry::class)->checks()))->toBe(['cache:array']);

        app(Recorder::class)->flush();
        expect($this->sentEvents('log')[0])->toMatchArray(['level' => 'error'])
            ->and($this->sentEvents('log')[0]['message'])->toContain('Bad Slug!');
    });

    it('skips a check that cannot be made, and runs the others', function () {
        $this->withConfig(['lumberjack.health.checks' => ['App\\Health\\Gone', CacheHealthCheck::class]]);

        expect(array_keys(app(HealthRegistry::class)->checks()))->toBe(['cache:array']);
    });

    it('boots and records when a release resolver throws', function () {
        $this->withConfig(['lumberjack.release' => null, 'lumberjack.release_resolver' => 'App\\Release\\Gone']);

        report(new RuntimeException('boom'));
        app()->terminate();

        expect($this->agent->payloads[0]['release'])->toBeNull()
            ->and($this->sentEvents('exception'))->toHaveCount(1);
    });
});

describe('nothing of the app runs late, inside its terminate', function () {
    it('takes what context objects say when they are recorded, and survives one that throws', function () {
        config()->set('logging.channels.lumberjack', ['driver' => 'lumberjack']);
        $calls = 0;
        $value = new class ($calls) implements JsonSerializable {
            public function __construct(private int &$calls)
            {
            }

            public function jsonSerialize(): mixed
            {
                $this->calls++;

                return ['total' => 42];
            }
        };
        $broken = new class () implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new LogicException('lazy loading violation');
            }
        };

        Log::channel('lumberjack')->info('order', ['order' => $value, 'broken' => $broken]);
        expect($calls)->toBe(1);

        app()->terminate();

        expect($calls)->toBe(1)
            ->and($this->sentEvents('log')[0]['context']['order'])->toBe(['total' => 42])
            ->and($this->sentEvents('log')[0]['context']['broken'])->toBe($broken::class);
    });

    it('keeps the app\'s terminate going when sending fails', function () {
        app()->instance(ExeQue\LumberjackAgent\AgentClient::class, new class () extends Tests\Support\FakeAgentClient {
            public function send(array $payload): bool
            {
                throw new RuntimeException('socket broke');
            }
        });
        app()->forgetInstance(Recorder::class);
        $this->refreshApplication();
        $ran = false;
        app()->terminating(function () use (&$ran): void {
            $ran = true;
        });

        report(new RuntimeException('boom'));
        app()->terminate();

        expect($ran)->toBeTrue();
    });
});
