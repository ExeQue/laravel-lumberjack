<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Listeners;

use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Support\CallerLocation;
use ExeQue\Lumberjack\Support\JobParameters;
use ExeQue\Lumberjack\Support\RecordingRules;
use ExeQue\Lumberjack\Support\Safely;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\Looping;
use Illuminate\Routing\Events\RouteMatched;
use Throwable;

/**
 * Turns Laravel's request, query and queue events into Lumberjack events.
 */
class RecordsLaravelEvents
{
    /** Listened to by name, so Octane is not needed. */
    public const string OCTANE_REQUEST_RECEIVED = 'Laravel\\Octane\\Events\\RequestReceived';

    public const string OCTANE_REQUEST_TERMINATED = 'Laravel\\Octane\\Events\\RequestTerminated';

    public const int MAX_DOMAIN = 255;

    /** @var array<string, float> */
    private array $jobStarts = [];

    /** @var array<string, true> jobs the rules leave out, by key */
    private array $ignoredJobs = [];

    /** Whether the current trace already belongs to a request that was handled. */
    private bool $traceUsed = false;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly RecordingRules $rules,
        private readonly ?CallerLocation $location = null,
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        // Always, so each request in a long-lived worker gets its own trace.
        $events->listen(RouteMatched::class, Safely::wrap($this->routeMatched(...)));
        $events->listen(RequestHandled::class, Safely::wrap($this->requestHandled(...)));
        $events->listen(self::OCTANE_REQUEST_RECEIVED, Safely::wrap(fn () => $this->newRequest()));

        if ($this->rules->queries) {
            $events->listen(QueryExecuted::class, Safely::wrap($this->queryExecuted(...)));
        }

        if ($this->rules->jobs) {
            $events->listen(JobQueued::class, Safely::wrap($this->jobQueued(...)));
            $events->listen(JobProcessing::class, Safely::wrap($this->jobProcessing(...)));
            $events->listen(JobProcessed::class, Safely::wrap(fn (JobProcessed $e) => $this->jobFinished($e->connectionName, $e->job, 'processed')));
            $events->listen(JobReleasedAfterException::class, Safely::wrap(fn (JobReleasedAfterException $e) => $this->jobFinished($e->connectionName, $e->job, 'released')));
            $events->listen(JobFailed::class, Safely::wrap($this->jobFailed(...)));
            $events->listen(Looping::class, Safely::wrap(fn () => $this->recorder->flush()));
        }
    }

    /**
     * Octane hands this worker its next request: what the last one left is sent, and the
     * new one starts on a trace of its own.
     */
    public function newRequest(): void
    {
        $this->recorder->flush();
        $this->recorder->reset();
        $this->traceUsed = false;
    }

    public function routeMatched(RouteMatched $event): void
    {
        // A worker serving its next request without Octane's events, e.g. tests.
        if ($this->traceUsed) {
            $this->newRequest();
        }

        if (! $this->rules->requests) {
            return;
        }

        if ($this->rules->ignoresRoute($event->request->method(), $event->route->uri(), $event->request->path())) {
            $this->recorder->capture(false);

            return;
        }

        // Sampled per request; timing and queries start once the route is known.
        $this->recorder->startRun(RecordingRules::sampled($this->rules->sampleRate), $this->rules->sampleRate);
    }

    public function requestHandled(RequestHandled $event): void
    {
        $this->traceUsed = true;

        if (! $this->rules->requests || ! $this->recorder->inRun()) {
            return;
        }

        $request = $event->request;
        // The request's own start: under Octane LARAVEL_START is when the worker booted.
        $start = (float) ($request->server('REQUEST_TIME_FLOAT') ?? (defined('LARAVEL_START') ? LARAVEL_START : microtime(true)));
        $duration = round((microtime(true) - $start) * 1000, 3);
        $status = $event->response->getStatusCode();
        $route = $request->route();
        $routeName = is_object($route) ? $route->getName() : null;
        $route = is_object($route) ? '/'.ltrim($route->uri(), '/') : '/'.ltrim($request->path(), '/');
        $domain = self::domain($request);

        // Every request is counted, sampled or not, so the server knows the true traffic.
        if (! $this->rules->ignoresStatus($status)) {
            $this->recorder->countRequest($request->method(), $route, $status, $duration, $domain);
        }

        // A request that was not sampled is still recorded when it was slow.
        if ($this->recorder->isTentative() && ! RecordingRules::isSlow($duration, $this->rules->requestsAlwaysSlowerThanMs)) {
            $this->recorder->endRun(keep: false, slowQueryMs: $this->rules->alwaysSlowerThanMs);

            return;
        }

        $this->recorder->endRun();

        if ($this->rules->ignoresStatus($status)) {
            $this->recorder->discardQueries();

            return;
        }

        $this->recorder->record([
            'type' => 'request',
            ...$this->recorder->weight(),
            'method' => $request->method(),
            'route' => $route,
            'route_name' => $routeName,
            'domain' => $domain,
            'url' => self::url($request),
            'status' => $status,
            'duration_ms' => $duration,
            'memory_peak' => memory_get_peak_usage(true),
            'timestamp' => $start,
        ]);
    }

    /**
     * The host the request was made to, with its port only when it is not the default. The
     * client chooses it: a malformed one (Symfony refuses it) is empty, a long one is cut.
     */
    private static function domain(Request $request): string
    {
        try {
            return mb_substr(strtolower($request->getHttpHost()), 0, self::MAX_DOMAIN);
        } catch (Throwable) {
            return '';
        }
    }

    private static function url(Request $request): string
    {
        try {
            return $request->fullUrl();
        } catch (Throwable) {
            return '/'.ltrim($request->path(), '/');
        }
    }

    public function queryExecuted(QueryExecuted $event): void
    {
        $time = (float) $event->time;

        if (! $this->recorder->capturing() || $this->rules->ignoresQuery($event->sql, $event->connectionName, $time)) {
            return;
        }

        $this->recorder->record(array_filter([
            'type' => 'query',
            'sql' => $event->sql,
            'connection' => $event->connectionName,
            'duration_ms' => $event->time,
            'location' => $this->rules->queryLocation ? $this->location?->find() : null,
            'timestamp' => microtime(true) - $event->time / 1000,
        ], fn ($value): bool => $value !== null));
    }

    public function jobQueued(JobQueued $event): void
    {
        if ($event->connectionName === 'sync') {
            return;
        }

        $payload = $event->payload();
        $name = (string) ($payload['displayName'] ?? '');

        if ($this->rules->ignoresJob($name, $event->queue)) {
            return;
        }

        $this->recorder->record(array_filter([
            'type' => 'job',
            'status' => 'queued',
            'uuid' => $payload['uuid'] ?? null,
            'name' => $name !== '' ? $name : null,
            'connection' => $event->connectionName,
            'queue' => $event->queue,
            'attempt' => 1,
            'max_tries' => $payload['maxTries'] ?? null,
            'timeout' => $payload['timeout'] ?? null,
            'parameters' => $this->rules->jobParameters ? JobParameters::from($event->job) : null,
        ], fn ($value): bool => $value !== null));
    }

    public function jobProcessing(JobProcessing $event): void
    {
        $key = $this->key($event->job);

        if ($this->rules->ignoresJob($event->job->resolveName(), $event->job->getQueue())) {
            $this->ignoredJobs[$key] = true;

            return;
        }

        // A sync job is part of the request that runs it; it keeps that trace.
        if ($event->connectionName !== 'sync') {
            $this->recorder->flush();
            $this->recorder->startTrace();
            $this->recorder->startRun(RecordingRules::sampled($this->rules->jobsSampleRate), $this->rules->jobsSampleRate);
        }

        $this->jobStarts[$key] = microtime(true);
        $this->recorder->record([...$this->job($event->connectionName, $event->job, 'processing'), ...$this->recorder->weight()]);
    }

    public function jobFailed(JobFailed $event): void
    {
        if (isset($this->ignoredJobs[$this->key($event->job)])) {
            unset($this->ignoredJobs[$this->key($event->job)]);

            return;
        }

        $this->recorder->markReported($event->exception);

        $this->jobFinished($event->connectionName, $event->job, 'failed', [
            'exception' => $this->recorder->present($event->exception),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function jobFinished(string $connection, Job $job, string $status, array $extra = []): void
    {
        $key = $this->key($job);

        if (isset($this->ignoredJobs[$key])) {
            unset($this->ignoredJobs[$key]);

            return;
        }

        $start = $this->jobStarts[$key] ?? null;
        unset($this->jobStarts[$key]);

        $duration = $start !== null ? round((microtime(true) - $start) * 1000, 3) : null;

        if ($connection !== 'sync') {
            // A failed job is worth its queries too, sampled or not. What the run held
            // goes out before its last event, so the trace reads in order.
            $this->recorder->endRun(
                keep: $status === 'failed' || RecordingRules::isSlow($duration, $this->rules->jobsAlwaysSlowerThanMs),
                slowQueryMs: $this->rules->alwaysSlowerThanMs,
            );
        }

        $this->recorder->record([
            ...$this->job($connection, $job, $status),
            ...$this->recorder->weight(),
            'duration_ms' => $duration,
            ...$extra,
        ]);

        if ($connection !== 'sync') {
            $this->recorder->flush();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function job(string $connection, Job $job, string $status): array
    {
        return array_filter([
            'type' => 'job',
            'status' => $status,
            'uuid' => $job->uuid(),
            'name' => $job->resolveName(),
            'connection' => $connection,
            'queue' => $job->getQueue(),
            'attempt' => $job->attempts(),
            'max_tries' => $job->maxTries(),
            'timeout' => $job->timeout(),
        ], fn ($value): bool => $value !== null);
    }

    private function key(Job $job): string
    {
        return ($job->uuid() ?? spl_object_id($job)).':'.$job->attempts();
    }
}
