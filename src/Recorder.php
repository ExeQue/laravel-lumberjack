<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack;

use Closure;
use ExeQue\Lumberjack\Support\Client;
use ExeQue\Lumberjack\Support\ExceptionContext;
use ExeQue\Lumberjack\Support\ExceptionPresenter;
use ExeQue\Lumberjack\Support\RecordingRules;
use ExeQue\Lumberjack\Support\Scrubber;
use Throwable;
use WeakMap;

/**
 * Buffers events for the current request or job and hands them to the agent
 * in one go when it ends.
 */
class Recorder
{
    // ponytail: flush early so long commands do not grow without bound.
    public const int MAX_BUFFERED = 1000;

    public const int MAX_HELD = 1000;

    /** Distinct method, route and status rows counted between two flushes; more go in one "other" row. */
    public const int MAX_COUNTED = 500;

    /** @var array<string, array{method: string, route: string, status: int, count: int, duration_ms_sum: float}> */
    private array $counts = [];

    private ?float $countedFrom = null;

    /** The rate that picked the current (or last) run, and whether it was picked. */
    private float $sampleRate = 1.0;

    private bool $sampled = true;

    /** @var list<array<string, mixed>> */
    private array $events = [];

    private ?string $traceId = null;

    /** Whether queries and request timing are recorded right now. */
    private bool $capturing = false;

    private bool $inRun = false;

    private bool $tentative = false;

    /** @var list<array<string, mixed>> queries and spans of an unsampled run, until it ends */
    private array $held = [];

    private bool $enabled;

    private int $exceptions = 0;

    /** @var WeakMap<Throwable, true> */
    private WeakMap $reported;

    /**
     * @param  array{environment?: ?string, release?: (Closure(): ?string)|string|null, project?: ?string}  $config  release may be worked out at the first flush
     */
    public function __construct(
        private readonly AgentClient $client,
        private readonly ExceptionPresenter $presenter,
        private readonly ExceptionContext $context,
        private readonly RecordingRules $rules,
        private array $config,
        bool $enabled = true,
        private readonly Scrubber $scrubber = new Scrubber(),
    ) {
        $this->enabled = $enabled;
        $this->reported = new WeakMap();
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function disable(): void
    {
        $this->enabled = false;
        $this->events = [];
    }

    public function traceId(): string
    {
        return $this->traceId ??= bin2hex(random_bytes(16));
    }

    /**
     * Starts a new trace, e.g. for the next job in a worker.
     */
    public function startTrace(): string
    {
        return $this->traceId = bin2hex(random_bytes(16));
    }

    /**
     * A new request in a long-lived worker (Octane, a test): a new trace, and nothing of
     * the last request's run carries over.
     */
    public function reset(): void
    {
        $this->startTrace();
        $this->inRun = false;
        $this->capturing = false;
        $this->tentative = false;
        $this->held = [];
    }

    public function capturing(): bool
    {
        return $this->enabled && $this->capturing;
    }

    public function capture(bool $capture = true): void
    {
        $this->capturing = $capture;
    }

    /**
     * A request, job or command run begins. A run that was not sampled still captures,
     * but holds its queries and spans back until endRun() knows whether it was slow.
     */
    public function startRun(bool $sampled = true, float $rate = 1.0): void
    {
        $this->sampleRate = max(0.0, min(1.0, $rate));
        $this->sampled = $sampled;
        $this->inRun = true;
        $this->capturing = true;
        $this->tentative = ! $sampled;
        $this->held = [];
    }

    /**
     * Ends the run. A run that was not sampled sends what it held only when $keep (it
     * turned out slow), and otherwise just its queries at least $slowQueryMs slow.
     */
    public function endRun(bool $keep = true, float $slowQueryMs = 0): void
    {
        $held = $this->held;
        $tentative = $this->tentative;
        $this->inRun = false;
        $this->capturing = false;
        $this->tentative = false;
        $this->held = [];

        if (! $tentative) {
            return;
        }

        foreach ($held as $event) {
            if ($keep || ($slowQueryMs > 0 && ($event['type'] ?? null) === 'query' && (float) ($event['duration_ms'] ?? 0) >= $slowQueryMs)) {
                $this->push($event);
            }
        }
    }

    /**
     * What a run's events carry so the server can weigh them: the rate that applied, and
     * whether the run was picked (false: kept only because it was slow or failed).
     *
     * @return array{sample_rate: float, sampled: bool}
     */
    public function weight(): array
    {
        return ['sample_rate' => $this->sampleRate, 'sampled' => $this->sampled];
    }

    /**
     * Every request the rules record, sampled or not, is counted, so the server knows the
     * true traffic. Sent as one request_counts event with the next flush.
     */
    public function countRequest(string $method, string $route, int $status, float $durationMs): void
    {
        if (! $this->enabled) {
            return;
        }

        $key = $method.' '.$route.' '.$status;

        if (! isset($this->counts[$key]) && count($this->counts) >= self::MAX_COUNTED) {
            $key = 'other';
            [$method, $route, $status] = ['*', 'other', 0];
        }

        $this->countedFrom ??= microtime(true);
        $this->counts[$key] ??= ['method' => $method, 'route' => $route, 'status' => $status, 'count' => 0, 'duration_ms_sum' => 0.0];
        $this->counts[$key]['count']++;
        $this->counts[$key]['duration_ms_sum'] += $durationMs;
    }

    /** A run that was not sampled: what it records is held back. */
    public function isTentative(): bool
    {
        return $this->tentative;
    }

    /** Inside a request, job or command run, whether or not it was sampled. */
    public function inRun(): bool
    {
        return $this->enabled && $this->inRun;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function record(array $event): void
    {
        if (! $this->enabled) {
            return;
        }

        $event = $this->scrubber->event($event + ['trace_id' => $this->traceId(), 'timestamp' => microtime(true)]);

        // A run's queries and spans weigh what the run weighs.
        if ($this->inRun && in_array($event['type'] ?? null, ['query', 'span'], true)) {
            $event += ['sample_rate' => $this->sampleRate, 'sampled' => ! $this->tentative];
        }

        if ($this->tentative && in_array($event['type'] ?? null, ['query', 'span'], true)) {
            // ponytail: an unsampled run keeps at most MAX_HELD; a slow run past that is cut short.
            if (count($this->held) < self::MAX_HELD) {
                $this->held[] = $event;
            }

            return;
        }

        $this->push($event);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function push(array $event): void
    {
        $this->events[] = $event;

        if (count($this->events) >= self::MAX_BUFFERED) {
            $this->flush();
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function exception(Throwable $e, array $context = []): void
    {
        if (! $this->enabled || ! $this->rules->exceptions || $this->wasReported($e) || $this->rules->ignoresException($e)) {
            return;
        }

        $this->markReported($e);
        $this->exceptions++;
        $this->record(['type' => 'exception', ...$this->presenter->present($e), 'context' => [...$this->context->collect(), ...$context]]);
    }

    /**
     * @return array{class: class-string, message: string, file: string, line: int, stacktrace: list<array<string, mixed>>}
     */
    public function present(Throwable $e): array
    {
        return $this->presenter->present($e);
    }

    /**
     * How many exceptions this process has sent so far.
     */
    public function exceptionCount(): int
    {
        return $this->exceptions;
    }

    public function markReported(Throwable $e): void
    {
        $this->reported[$e] = true;
    }

    public function wasReported(Throwable $e): bool
    {
        return isset($this->reported[$e]);
    }

    /**
     * Drops the queries buffered for the current trace, e.g. for a request the rules leave out.
     */
    public function discardQueries(): void
    {
        $trace = $this->traceId;
        $this->events = array_values(array_filter($this->events, fn (array $event): bool => ! (($event['type'] ?? null) === 'query' && ($event['trace_id'] ?? null) === $trace)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        return $this->events;
    }

    public function release(): ?string
    {
        $release = $this->config['release'] ?? null;

        if ($release instanceof Closure) {
            $release = $this->config['release'] = $release();
        }

        return $release;
    }

    public function flush(): void
    {
        if ($this->counts !== []) {
            $now = microtime(true);
            $this->events[] = [
                'type' => 'request_counts',
                'counts' => array_values(array_map(fn (array $row): array => ['duration_ms_sum' => round($row['duration_ms_sum'], 3)] + $row, $this->counts)),
                'from' => $this->countedFrom ?? $now,
                'to' => $now,
                'trace_id' => $this->traceId(),
                'timestamp' => $now,
            ];
            $this->counts = [];
            $this->countedFrom = null;
        }

        if ($this->events === []) {
            return;
        }

        $events = $this->events;
        $this->events = [];

        $this->client->send([
            'v' => 1,
            'sdk' => ['name' => Client::SDK, 'version' => Client::version()],
            'environment' => $this->config['environment'] ?? null,
            'release' => $this->release(),
            'host' => gethostname() ?: null,
            'project' => $this->config['project'] ?? null,
            'events' => $events,
        ]);
    }
}
