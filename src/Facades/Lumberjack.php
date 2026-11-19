<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Facades;

use Closure;
use ExeQue\Lumberjack\Activity\ActivityActors;
use ExeQue\Lumberjack\Activity\Actor;
use ExeQue\Lumberjack\Activity\Reference;
use ExeQue\Lumberjack\Ai\AiCall;
use ExeQue\Lumberjack\Ai\AiCallRecorder;
use ExeQue\Lumberjack\Contracts\ActsAsLumberjackActor;
use ExeQue\Lumberjack\DataSources\DataSourceRegistry;
use ExeQue\Lumberjack\Exceptions\ForgetActorFailedException;
use ExeQue\Lumberjack\Health\HealthCheckHandle;
use ExeQue\Lumberjack\Health\HealthRegistry;
use ExeQue\Lumberjack\Health\HealthStates;
use ExeQue\Lumberjack\Health\HealthStateSnapshot;
use ExeQue\Lumberjack\LumberjackServiceProvider;
use ExeQue\Lumberjack\PendingActivity;
use ExeQue\Lumberjack\PendingAlert;
use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Support\Safely;
use ExeQue\Lumberjack\Testing\EventType;
use ExeQue\Lumberjack\Testing\FakeAgentClient;
use ExeQue\Lumberjack\Testing\LumberjackFake;
use ExeQue\Lumberjack\Testing\RecordedActivity;
use ExeQue\Lumberjack\Testing\RecordedAiCall;
use ExeQue\Lumberjack\Testing\RecordedAlert;
use ExeQue\Lumberjack\Webhooks\Handlers;
use ExeQue\LumberjackAgent\AgentClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * @method static Handlers on(\ExeQue\Lumberjack\Webhooks\Status|list<\ExeQue\Lumberjack\Webhooks\Status> $statuses, callable $handler)
 * @method static Handlers onException(string|list<string> $classes, \ExeQue\Lumberjack\Webhooks\Status|list<\ExeQue\Lumberjack\Webhooks\Status> $statuses, callable $handler)
 *
 * @see Handlers
 */
class Lumberjack extends Facade
{
    /**
     * The data sources this app offers Lumberjack: add() a DataSource, or a class the
     * container makes when it is needed.
     */
    public static function dataSources(): DataSourceRegistry
    {
        return app(DataSourceRegistry::class);
    }

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
     * An activity with this name: something that happened in the app, recorded with the
     * request or job by send().
     */
    public static function activity(string $name): PendingActivity
    {
        return new PendingActivity(app(Recorder::class), $name);
    }

    /**
     * Deletes every activity of one actor in Lumberjack, such as when the account behind it
     * is deleted: a model, or a type and its key. Throws when Lumberjack did not do it.
     *
     * @return int activities deleted
     *
     * @throws ForgetActorFailedException
     */
    public static function forgetActor(ActsAsLumberjackActor|Actor|Model|string $actor, string|int|null $id = null): int
    {
        return app(ActivityActors::class)->forget($actor, $id);
    }

    /**
     * Runs the callback with AI calls made for this actor, with a key of this owner (a
     * tenant bringing its own key) and label. Scopes nest: an inner one overrides only
     * what it sets. Returns what the callback returns; a stream it returns (a generator, or
     * Laravel AI's streamable response) keeps the scope while it is read later.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function ai(Closure $callback, ActsAsLumberjackActor|Actor|Model|null $by = null, ActsAsLumberjackActor|Actor|Model|null $owner = null, ?string $label = null): mixed
    {
        $reference = static function (ActsAsLumberjackActor|Actor|Model|null $value): ?Reference {
            try {
                return Reference::of($value);
            } catch (Throwable $e) {
                Safely::problem('Lumberjack could not tell who an AI call is for: '.$e::class);

                return null;
            }
        };

        // The callback always runs: it is the app's own work.
        return app(Recorder::class)->aiContext()->run($callback, $reference($by), $reference($owner), $label);
    }

    /**
     * Works out the actor, the key's owner and its label of each AI call: return an array
     * with any of by, owner and label, or null to leave the call as it is. Comes before a
     * Lumberjack::ai() scope. AiCall::usesKey() tells which key the call was made with.
     *
     * @param  (Closure(AiCall): ?array<string, mixed>)|null  $resolver
     */
    public static function resolveAiKeyUsing(?Closure $resolver): void
    {
        app(AiCallRecorder::class)->resolveKeysUsing($resolver);
    }

    /**
     * Records an AI call the app made itself, not through Laravel AI or Prism. $input is
     * every input token, cached ones included; $output every output token, reasoning
     * included. The key is only fingerprinted, never sent. Never throws.
     *
     * @param  string  $operation  text, structured, stream, embeddings, image, audio, transcription or rerank
     */
    public static function aiUsage(
        string $provider,
        string $model,
        ?int $input = null,
        ?int $output = null,
        ?int $cacheRead = null,
        ?int $cacheWrite = null,
        ?int $reasoning = null,
        #[SensitiveParameter] ?string $key = null,
        string $operation = 'text',
        ?float $durationMs = null,
        ?string $driver = null,
        ?string $error = null,
        ActsAsLumberjackActor|Actor|Model|null $by = null,
        ActsAsLumberjackActor|Actor|Model|null $owner = null,
        ?string $label = null,
    ): void {
        $driver = strtolower($driver ?? $provider);
        $call = new AiCall(
            source: 'manual',
            provider: $provider,
            driver: $driver,
            model: $model,
            operation: $operation,
            inputTokens: $input,
            outputTokens: $output,
            cacheReadTokens: $cacheRead,
            cacheWriteTokens: $cacheWrite,
            reasoningTokens: $reasoning,
            durationMs: $durationMs,
            error: $error,
            keySha256: AiCall::fingerprint($driver, $key),
        );

        Safely::run(fn () => app(AiCallRecorder::class)->record($call, ['by' => $by, 'owner' => $owner, 'label' => $label]));
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

        $actors = $app->make(ActivityActors::class);
        $actors->fake();
        $fake = new LumberjackFake($recorder, $client, $actors);
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
    public static function assertReported(string $exception, string|Closure|null $message = null, ?int $times = null): void
    {
        self::faked()->assertReported($exception, $message, $times);
    }

    /**
     * @param  class-string  $exception  this class or one extending it
     */
    public static function assertNotReported(string $exception, string|Closure|null $message = null): void
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

    /**
     * @param  string|(Closure(RecordedActivity): bool)|null  $activity  a name, or a check of each activity
     * @return list<RecordedActivity>
     */
    public static function activities(string|Closure|null $activity = null, ?string $group = null): array
    {
        return self::faked()->activities($activity, $group);
    }

    /**
     * @param  string|(Closure(RecordedActivity): bool)|null  $activity  a name, or a check of each activity
     */
    public static function assertActivityRecorded(string|Closure|null $activity = null, ?string $group = null, ?int $times = null): void
    {
        self::faked()->assertActivityRecorded($activity, $group, $times);
    }

    /**
     * @param  string|(Closure(RecordedActivity): bool)|null  $activity  a name, or a check of each activity
     */
    public static function assertActivityNotRecorded(string|Closure|null $activity = null, ?string $group = null): void
    {
        self::faked()->assertActivityNotRecorded($activity, $group);
    }

    /**
     * @param  string|(Closure(RecordedAiCall): bool)|null  $call  a model, or a check of each call
     * @return list<RecordedAiCall>
     */
    public static function aiCalls(string|Closure|null $call = null, ?string $provider = null): array
    {
        return self::faked()->aiCalls($call, $provider);
    }

    /**
     * @param  string|(Closure(RecordedAiCall): bool)|null  $call  a model, or a check of each call
     */
    public static function assertAiCallRecorded(string|Closure|null $call = null, ?string $provider = null, ?int $times = null): void
    {
        self::faked()->assertAiCallRecorded($call, $provider, $times);
    }

    /**
     * @param  string|(Closure(RecordedAiCall): bool)|null  $call  a model, or a check of each call
     */
    public static function assertAiCallNotRecorded(string|Closure|null $call = null, ?string $provider = null): void
    {
        self::faked()->assertAiCallNotRecorded($call, $provider);
    }

    public static function assertActorForgotten(Model|string $actor, string|int|null $id = null): void
    {
        self::faked()->assertActorForgotten($actor, $id);
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
