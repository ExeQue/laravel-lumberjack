<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Facades;

use Closure;
use ExeQue\Lumberjack\Health\HealthCheckHandle;
use ExeQue\Lumberjack\Health\HealthRegistry;
use ExeQue\Lumberjack\Health\HealthStates;
use ExeQue\Lumberjack\Health\HealthStateSnapshot;
use ExeQue\Lumberjack\LumberjackServiceProvider;
use ExeQue\Lumberjack\PendingAlert;
use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Testing\EventType;
use ExeQue\Lumberjack\Testing\FakeAgentClient;
use ExeQue\Lumberjack\Testing\LumberjackFake;
use ExeQue\Lumberjack\Testing\RecordedAlert;
use ExeQue\Lumberjack\Webhooks\Handlers;
use ExeQue\LumberjackAgent\AgentClient;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

/**
 * @method static Handlers on(\ExeQue\Lumberjack\Webhooks\Status|list<\ExeQue\Lumberjack\Webhooks\Status> $statuses, callable $handler)
 * @method static Handlers onException(string|list<string> $classes, \ExeQue\Lumberjack\Webhooks\Status|list<\ExeQue\Lumberjack\Webhooks\Status> $statuses, callable $handler)
 *
 * @see Handlers
 */
class Lumberjack extends Facade
{
    /**
     * The health check registry: the one place checks are registered, through its
     * fluent built-ins, check() and add().
     */
    public static function healthChecks(): HealthRegistry
    {
        return app(HealthRegistry::class);
    }

    /**
     * A handle to fire the registered check with this slug from the app's own code, or
     * to read where Lumberjack says any check stands. It never registers anything.
     */
    public static function health(string $slug): HealthCheckHandle
    {
        return new HealthCheckHandle(app(HealthRegistry::class), app(), $slug);
    }

    /**
     * Every check Lumberjack knows for this app, by slug, as it last said they stand.
     *
     * @return array<string, HealthStateSnapshot>
     */
    public static function healthStates(): array
    {
        return app(HealthStates::class)->all();
    }

    /**
     * A custom alert with this title, sent to the channels of its topic by send().
     */
    public static function alert(string $title): PendingAlert
    {
        return new PendingAlert(app(Recorder::class), $title);
    }

    /**
     * Keeps everything the app records from here on for assertions, instead of sending
     * it. Works with recording turned off too.
     */
    public static function fake(): LumberjackFake
    {
        $app = app();
        $client = new FakeAgentClient();
        $recorder = $app->make(Recorder::class);

        $app->instance(AgentClient::class, $client);
        $recorder->fake($client);
        $provider = $app->getProvider(LumberjackServiceProvider::class);

        if ($provider instanceof LumberjackServiceProvider) {
            $provider->startRecording();
        }

        $fake = new LumberjackFake($recorder, $client);
        $app->instance(LumberjackFake::class, $fake);

        return $fake;
    }

    /**
     * @param  array<string, mixed>|(Closure(array<string, mixed>): bool)|null  $matching  values by key (dot notation), or a check of each event
     * @return list<array<string, mixed>>
     */
    public static function recorded(?EventType $type = null, array|Closure|null $matching = null): array
    {
        return self::faked()->recorded($type, $matching);
    }

    /**
     * @param  array<string, mixed>|(Closure(array<string, mixed>): bool)|null  $matching  values by key (dot notation), or a check of each event
     */
    public static function assertRecorded(EventType $type, array|Closure|null $matching = null, ?int $times = null): void
    {
        self::faked()->assertRecorded($type, $matching, $times);
    }

    /**
     * @param  array<string, mixed>|(Closure(array<string, mixed>): bool)|null  $matching  values by key (dot notation), or a check of each event
     */
    public static function assertNotRecorded(EventType $type, array|Closure|null $matching = null): void
    {
        self::faked()->assertNotRecorded($type, $matching);
    }

    public static function assertNothingRecorded(): void
    {
        self::faked()->assertNothingRecorded();
    }

    /**
     * @param  class-string  $exception  this class or one extending it
     */
    public static function assertReported(string $exception, ?string $message = null, ?int $times = null): void
    {
        self::faked()->assertReported($exception, $message, $times);
    }

    /**
     * @param  class-string  $exception  this class or one extending it
     */
    public static function assertNotReported(string $exception, ?string $message = null): void
    {
        self::faked()->assertNotReported($exception, $message);
    }

    /**
     * @param  string|(Closure(RecordedAlert): bool)|null  $alert  a title, or a check of each alert
     * @return list<RecordedAlert>
     */
    public static function alerts(string|Closure|null $alert = null, ?string $topic = null, ?string $level = null): array
    {
        return self::faked()->alerts($alert, $topic, $level);
    }

    /**
     * @param  string|(Closure(RecordedAlert): bool)|null  $alert  a title, or a check of each alert
     */
    public static function assertAlerted(string|Closure|null $alert = null, ?string $topic = null, ?string $level = null, ?int $times = null): void
    {
        self::faked()->assertAlerted($alert, $topic, $level, $times);
    }

    /**
     * @param  string|(Closure(RecordedAlert): bool)|null  $alert  a title, or a check of each alert
     */
    public static function assertNotAlerted(string|Closure|null $alert = null, ?string $topic = null, ?string $level = null): void
    {
        self::faked()->assertNotAlerted($alert, $topic, $level);
    }

    public static function assertNothingAlerted(): void
    {
        self::faked()->assertNothingAlerted();
    }

    private static function faked(): LumberjackFake
    {
        if (! app()->bound(LumberjackFake::class)) {
            throw new RuntimeException('Call Lumberjack::fake() before asserting on what was recorded.');
        }

        return app(LumberjackFake::class);
    }

    protected static function getFacadeAccessor(): string
    {
        return Handlers::class;
    }
}
