<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack;

use ExeQue\Lumberjack\Support\ExceptionContext;
use ExeQue\Lumberjack\Support\ExceptionPresenter;
use ExeQue\Lumberjack\Support\RecordingRules;
use Throwable;
use WeakMap;

/**
 * Buffers events for the current request or job and hands them to the agent
 * in one go when it ends.
 */
class Recorder
{
    public const string VERSION = '0.1.0';

    // ponytail: flush early so long commands do not grow without bound.
    public const int MAX_BUFFERED = 1000;

    /** @var list<array<string, mixed>> */
    private array $events = [];

    private ?string $traceId = null;

    /** Whether queries and request timing are recorded right now. */
    private bool $capturing = false;

    private bool $enabled;

    private int $exceptions = 0;

    /** @var WeakMap<Throwable, true> */
    private WeakMap $reported;

    /**
     * @param  array{environment?: ?string, release?: ?string}  $config
     */
    public function __construct(
        private readonly AgentClient $client,
        private readonly ExceptionPresenter $presenter,
        private readonly ExceptionContext $context,
        private readonly RecordingRules $rules,
        private readonly array $config,
        bool $enabled = true,
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

    public function capturing(): bool
    {
        return $this->enabled && $this->capturing;
    }

    public function capture(bool $capture = true): void
    {
        $this->capturing = $capture;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function record(array $event): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->events[] = $event + ['trace_id' => $this->traceId(), 'timestamp' => microtime(true)];

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

    public function flush(): void
    {
        if ($this->events === []) {
            return;
        }

        $events = $this->events;
        $this->events = [];

        $this->client->send([
            'v' => 1,
            'sdk' => 'exeque/laravel-lumberjack@'.self::VERSION,
            'environment' => $this->config['environment'] ?? null,
            'release' => $this->config['release'] ?? null,
            'host' => gethostname() ?: null,
            'events' => $events,
        ]);
    }
}
