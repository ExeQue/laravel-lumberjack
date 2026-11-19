<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Ai\LaravelAi;

use Closure;
use ExeQue\Lumberjack\Activity\Reference;
use ExeQue\Lumberjack\Ai\AiCall;
use ExeQue\Lumberjack\Ai\AiCallRecorder;
use ExeQue\Lumberjack\Ai\TokenNormalizer;
use ExeQue\Lumberjack\Support\Safely;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Jobs\SyncJob;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\AudioFailed;
use Laravel\Ai\Events\AudioGenerated;
use Laravel\Ai\Events\ClassificationFailed;
use Laravel\Ai\Events\Classified;
use Laravel\Ai\Events\Classifying;
use Laravel\Ai\Events\EmbeddingsFailed;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingAudio;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\GeneratingImage;
use Laravel\Ai\Events\GeneratingTranscription;
use Laravel\Ai\Events\ImageFailed;
use Laravel\Ai\Events\ImageGenerated;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Events\Reranked;
use Laravel\Ai\Events\Reranking;
use Laravel\Ai\Events\RerankingFailed;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\TranscriptionFailed;
use Laravel\Ai\Events\TranscriptionGenerated;
use Throwable;

/**
 * Records every provider call Laravel AI makes (0.11 and 1.x): each step of an agent's
 * run (text, structured or streamed, a tool loop is several), and embeddings, images,
 * audio, transcriptions, reranking and classification. AgentPrompted is not recorded: it
 * sums the steps, which are recorded already. Events a version does not have are skipped.
 *
 * A step that started but never completed nor failed (a stream the reader stopped early,
 * as on a disconnect) is recorded with its operation (`stream`, else `text` or
 * `structured`) and without tokens, in the scope it started in, when its agent is done,
 * the request ends, a queued job ends or it is pushed out past MAX_OPEN. On 0.x, which has no *Failed events, an embeddings,
 * image, audio, transcription or reranking call that never finished nor failed over is
 * recorded then as an error ("Unfinished").
 */
final class RecordsLaravelAi
{
    /** Calls started but not finished (or streams running) remembered at most. */
    public const int MAX_OPEN = 100;

    /** @var array<string, array{operation: string, started: float, provider: object, model: string, scope: array{by: ?Reference, owner: ?Reference, label: ?string}}> by invocation */
    private array $started = [];

    /** @var array<string, true> agent invocations that stream */
    private array $streaming = [];

    /** @var array<string, array{provider: object, model: string, invocationId: string, step: int, operation: string, scope: array{by: ?Reference, owner: ?Reference, label: ?string}, agent: array{by: ?Reference, owner: ?Reference, label: ?string}}> steps started, by invocation and step */
    private array $steps = [];

    /** @var array<string, true> steps recorded already, by invocation and step */
    private array $closed = [];

    public function __construct(private readonly AiCallRecorder $ai)
    {
    }

    public function subscribe(Dispatcher $events): void
    {
        $listen = function (string $event, Closure $listener) use ($events): void {
            if (class_exists($event)) {
                $events->listen($event, Safely::wrap($listener));
            }
        };

        $listen(PromptingAgent::class, fn (PromptingAgent $e) => $this->ai->starting($e->invocationId, $e->prompt->agent));
        $listen(StreamingAgent::class, function (StreamingAgent $e): void {
            $this->ai->starting($e->invocationId, $e->prompt->agent);
            $this->remember($this->streaming, $e->invocationId, true);
        });
        // The dispatcher matches classes exactly, so AgentStreamed is listened to on its own.
        $listen(AgentPrompted::class, fn (AgentPrompted $e) => $this->done($e->invocationId));
        $listen(AgentStreamed::class, fn (AgentStreamed $e) => $this->done($e->invocationId));
        $listen(AgentFailed::class, fn (AgentFailed $e) => $this->done($e->invocationId));
        $listen(StartingStep::class, function (StartingStep $e): void {
            $key = $e->invocationId.'#'.$e->stepNumber;

            // The same step again (a retry, a failover): the one before is over, recorded if it was not.
            if (isset($this->steps[$key])) {
                $this->abandoned($key);
            }

            unset($this->closed[$key]);

            // The scope and what the agent says now: a step left open is recorded later, outside it.
            $this->remember($this->steps, $key, [
                'provider' => $e->provider,
                'model' => $e->model,
                'invocationId' => $e->invocationId,
                'step' => $e->stepNumber,
                'operation' => $this->operation($e->invocationId, $e->agent),
                'scope' => $this->ai->scopeFor($e->invocationId),
                'agent' => $this->agent($e->invocationId, $e->agent),
            ], fn (array $step, string $key) => $this->abandoned($key, $step));
        });
        $listen(StepCompleted::class, fn (StepCompleted $e) => $this->step($e, $e->response->usage, null));
        $listen(StepFailed::class, fn (StepFailed $e) => $this->step($e, null, $e->exception));

        // A queued job's end; a request's is the app's terminating, set up by the service provider.
        foreach ([JobProcessed::class, JobFailed::class, JobReleasedAfterException::class] as $event) {
            // A sync, deferred or background job runs inside the request (or its own process): not here.
            $events->listen($event, Safely::wrap(function (JobProcessed|JobFailed|JobReleasedAfterException $e): void {
                if (! $e->job instanceof SyncJob) {
                    $this->recordOpen();
                }
            }));
        }

        $operations = [
            [GeneratingEmbeddings::class, EmbeddingsGenerated::class, EmbeddingsFailed::class, 'embeddings'],
            [GeneratingImage::class, ImageGenerated::class, ImageFailed::class, 'image'],
            [GeneratingAudio::class, AudioGenerated::class, AudioFailed::class, 'audio'],
            [GeneratingTranscription::class, TranscriptionGenerated::class, TranscriptionFailed::class, 'transcription'],
            [Reranking::class, Reranked::class, RerankingFailed::class, 'rerank'],
            [Classifying::class, Classified::class, ClassificationFailed::class, 'structured'],
        ];

        foreach ($operations as [$starting, $finished, $failed, $operation]) {
            $listen($starting, fn (GeneratingEmbeddings|GeneratingImage|GeneratingAudio|GeneratingTranscription|Reranking|Classifying $e) => $this->remember($this->started, $e->invocationId, ['operation' => $operation, 'started' => hrtime(true) / 1e6, 'provider' => $e->provider, 'model' => $e->model, 'scope' => $this->ai->scopeFor($e->invocationId)], $this->unfinished(...)));
            $listen($finished, fn (EmbeddingsGenerated|ImageGenerated|AudioGenerated|TranscriptionGenerated|Reranked|Classified $e) => $this->finished($e, $operation, self::usage($e->response), null));
            $listen($failed, fn (EmbeddingsFailed|ImageFailed|AudioFailed|TranscriptionFailed|RerankingFailed|ClassificationFailed $e) => $this->finished($e, $operation, null, $e->exception));
        }

        // 0.x has no *Failed events: a provider failing over is the only sign of its failed call.
        if (! class_exists(EmbeddingsFailed::class)) {
            $listen(ProviderFailedOver::class, $this->failedOver(...));
        }
    }

    /**
     * Records what started but never ended: steps as streams without tokens, and on 0.x
     * the other calls as unfinished errors.
     */
    public function recordOpen(): void
    {
        // Each on its own: one that fails never costs the others.
        foreach (array_keys($this->steps) as $key) {
            Safely::run($this->abandoned(...), $key);
        }

        $started = $this->started;
        $this->started = [];

        foreach ($started as $invocationId => $call) {
            Safely::run($this->unfinished(...), $call, (string) $invocationId);
        }

        foreach (array_keys($this->streaming) as $invocationId) {
            Safely::run($this->done(...), (string) $invocationId);
        }

        $this->ai->finished();
    }

    /**
     * The agent's run is over: what it left open is recorded. Its stream scope stays bound
     * until the next request or job, for calls made after it, such as a conversation title.
     */
    private function done(string $invocationId): void
    {
        foreach ($this->steps as $key => $step) {
            if ($step['invocationId'] === $invocationId) {
                $this->abandoned($key);
            }
        }

        unset($this->streaming[$invocationId]);
        $this->ai->finished($invocationId);
    }

    /**
     * A step that never ended, without tokens, in the scope it started in.
     *
     * @param  array{provider: object, model: string, invocationId: string, step: int, operation: string, scope: array{by: ?Reference, owner: ?Reference, label: ?string}, agent: array{by: ?Reference, owner: ?Reference, label: ?string}}|null  $step  given when it was pushed out of the open steps
     */
    private function abandoned(string $key, ?array $step = null): void
    {
        $step ??= $this->steps[$key];
        unset($this->steps[$key]);
        $this->remember($this->closed, $key, true);

        $this->record($step['provider'], $step['model'], $step['operation'], null, null, null, $step['invocationId'], $step['step'], $step['scope'], $step['agent']);
    }

    /**
     * On 0.x, which has no *Failed events, a call that never finished nor failed over
     * failed. On 1.x such a leftover only means its events were missed.
     *
     * @param  array{operation: string, started: float, provider: object, model: string, scope: array{by: ?Reference, owner: ?Reference, label: ?string}}  $call
     */
    private function unfinished(array $call, string $invocationId): void
    {
        if (! class_exists(EmbeddingsFailed::class)) {
            $this->record($call['provider'], $call['model'], $call['operation'], null, null, 'Unfinished', $invocationId, null, $call['scope']);
        }
    }

    /**
     * What the agent said when its run started; read now if that start was missed.
     *
     * @return array{by: ?Reference, owner: ?Reference, label: ?string}
     */
    private function agent(string $invocationId, object $agent): array
    {
        return $this->ai->agentFor($invocationId) ?? $this->ai->agentScope($agent);
    }

    private function operation(string $invocationId, object $agent): string
    {
        return match (true) {
            isset($this->streaming[$invocationId]) => 'stream',
            $agent instanceof HasStructuredOutput => 'structured',
            default => 'text',
        };
    }

    private function step(StepCompleted|StepFailed $event, mixed $usage, ?Throwable $exception): void
    {
        $key = $event->invocationId.'#'.$event->stepNumber;
        $agent = $this->steps[$key]['agent'] ?? null;
        // A run no longer known (pushed out past the cap) is placed by the scope its step started in.
        $scope = $this->ai->knows($event->invocationId) ? null : ($this->steps[$key]['scope'] ?? null);
        unset($this->steps[$key]);

        // Recorded already (left open or pushed out, then ending after all): once is enough.
        if (isset($this->closed[$key])) {
            return;
        }

        $agent ??= $this->agent($event->invocationId, $event->agent);

        $this->remember($this->closed, $key, true);
        $this->record($event->provider, self::model($event, $event->model), $this->operation($event->invocationId, $event->agent), $usage, $event->time, $exception, $event->invocationId, $event->stepNumber, $scope, $agent);
    }

    private function finished(EmbeddingsGenerated|ImageGenerated|AudioGenerated|TranscriptionGenerated|Reranked|Classified|EmbeddingsFailed|ImageFailed|AudioFailed|TranscriptionFailed|RerankingFailed|ClassificationFailed $event, string $operation, mixed $usage, ?Throwable $exception): void
    {
        $started = $this->started[$event->invocationId] ?? null;
        unset($this->started[$event->invocationId]);

        $this->record($event->provider, self::model($event, $event->model), $operation, $usage, $started === null ? null : hrtime(true) / 1e6 - $started['started'], $exception, $event->invocationId);
    }

    /**
     * Without an invocation id: the call that failed over is the last one started.
     */
    private function failedOver(ProviderFailedOver $event): void
    {
        $invocation = array_key_last($this->started);
        $started = null;

        if ($invocation !== null) {
            $started = $this->started[$invocation];
            unset($this->started[$invocation]);
        }

        $this->record($event->provider, $event->model, $started['operation'] ?? 'text', null, $started === null ? null : hrtime(true) / 1e6 - $started['started'], $event->exception, $invocation);
    }

    /**
     * @param  array{by: ?Reference, owner: ?Reference, label: ?string}|null  $scope  the scope it was made in, when it is recorded later
     * @param  array{by: ?Reference, owner: ?Reference, label: ?string}|null  $agent  what the agent making it says
     */
    private function record(object $provider, string $model, string $operation, mixed $usage, ?float $durationMs, Throwable|string|null $error, ?string $invocationId, ?int $step = null, ?array $scope = null, ?array $agent = null): void
    {
        $driver = method_exists($provider, 'driver') ? strtolower((string) $provider->driver()) : 'unknown';

        $this->ai->record(new AiCall(
            ...self::tokens($driver, $usage),
            source: 'laravel-ai',
            provider: method_exists($provider, 'name') ? (string) $provider->name() : $driver,
            driver: $driver,
            model: $model,
            operation: $operation,
            durationMs: $durationMs,
            error: $error instanceof Throwable ? class_basename($error) : $error,
            keySha256: AiCall::fingerprint($driver, self::key($provider)),
            invocationId: $invocationId,
            step: $step,
        ), scope: $scope, agent: $agent);
    }

    /**
     * The usage object of either version: 0.x has promptTokens and plain ints, 1.x
     * inputTokens and nullable cache and reasoning (on TextUsage only).
     *
     * @return array{inputTokens: ?int, outputTokens: ?int, cacheReadTokens: ?int, cacheWriteTokens: ?int, reasoningTokens: ?int}
     */
    private static function tokens(string $driver, mixed $usage): array
    {
        if (! is_object($usage)) {
            return TokenNormalizer::laravelAi(false, $driver, is_int($usage) ? $usage : null, null);
        }

        $legacy = property_exists($usage, 'promptTokens');

        return TokenNormalizer::laravelAi(
            $legacy,
            $driver,
            self::int($usage, $legacy ? 'promptTokens' : 'inputTokens'),
            self::int($usage, $legacy ? 'completionTokens' : 'outputTokens'),
            self::int($usage, 'cacheReadInputTokens'),
            self::int($usage, 'cacheWriteInputTokens'),
            self::int($usage, 'reasoningTokens'),
        );
    }

    /** 0.x embeddings have a token count instead; its audio and reranking have no usage. */
    private static function usage(object $response): mixed
    {
        return $response->usage ?? (isset($response->tokens) && is_int($response->tokens) ? $response->tokens : null);
    }

    private static function int(object $object, string $property): ?int
    {
        return isset($object->{$property}) && is_numeric($object->{$property}) ? (int) $object->{$property} : null;
    }

    /**
     * The key the provider is configured with, read and fingerprinted at once. A provider
     * without one (Bedrock, a local Ollama) has none.
     */
    private static function key(object $provider): ?string
    {
        try {
            $key = method_exists($provider, 'providerCredentials') ? ($provider->providerCredentials()['key'] ?? null) : null;
        } catch (Throwable) {
            return null;
        }

        return is_string($key) ? $key : null;
    }

    /** The model that answered, else the one asked for. */
    private static function model(object $event, string $asked): string
    {
        $meta = isset($event->response) && is_object($event->response) ? ($event->response->meta ?? null) : null;
        $model = is_object($meta) ? ($meta->model ?? null) : null;

        return is_string($model) && $model !== '' ? $model : $asked;
    }

    /**
     * @template TValue
     *
     * @param  array<string, TValue>  $map
     * @param  TValue  $value
     * @param  (Closure(TValue, string): void)|null  $evicted  records an entry pushed out past the cap
     */
    private function remember(array &$map, string $invocationId, mixed $value, ?Closure $evicted = null): void
    {
        // ponytail: the oldest goes past the cap; a call still open is recorded as it goes.
        if (count($map) >= self::MAX_OPEN) {
            $oldest = array_key_first($map);
            $entry = $map[$oldest];
            unset($map[$oldest]);

            if ($evicted !== null) {
                Safely::run($evicted, $entry, $oldest);
            }
        }

        $map[$invocationId] = $value;
    }

}
