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
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\Looping;
use Illuminate\Routing\Events\RouteMatched;

/**
 * Turns Laravel's request, query and queue events into Lumberjack events.
 */
class RecordsLaravelEvents
{
    /** @var array<string, float> */
    private array $jobStarts = [];

    /** @var array<string, true> jobs the rules leave out, by key */
    private array $ignoredJobs = [];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly RecordingRules $rules,
        private readonly ?CallerLocation $location = null,
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        if ($this->rules->requests) {
            $events->listen(RouteMatched::class, Safely::wrap($this->routeMatched(...)));
            $events->listen(RequestHandled::class, Safely::wrap($this->requestHandled(...)));
        }

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

    public function routeMatched(RouteMatched $event): void
    {
        if ($this->rules->ignoresRoute($event->request->method(), $event->route->uri(), $event->request->path())) {
            $this->recorder->capture(false);

            return;
        }

        // Sampled per request; timing and queries start once the route is known.
        $this->recorder->capture(mt_rand() / mt_getrandmax() < $this->rules->sampleRate);
    }

    public function requestHandled(RequestHandled $event): void
    {
        if (! $this->recorder->capturing()) {
            return;
        }

        $this->recorder->capture(false);

        $status = $event->response->getStatusCode();

        if ($this->rules->ignoresStatus($status)) {
            $this->recorder->discardQueries();

            return;
        }

        $request = $event->request;
        $route = $request->route();
        $start = defined('LARAVEL_START') ? (float) LARAVEL_START : (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));

        $this->recorder->record([
            'type' => 'request',
            'method' => $request->method(),
            'route' => is_object($route) ? '/'.ltrim($route->uri(), '/') : null,
            'url' => $request->fullUrl(),
            'status' => $status,
            'duration_ms' => round((microtime(true) - $start) * 1000, 3),
            'memory_peak' => memory_get_peak_usage(true),
            'timestamp' => $start,
        ]);
    }

    public function queryExecuted(QueryExecuted $event): void
    {
        if (! $this->recorder->capturing() || $this->rules->ignoresQuery($event->sql, $event->connectionName, (float) $event->time)) {
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
            $this->recorder->capture();
        }

        $this->jobStarts[$key] = microtime(true);
        $this->recorder->record($this->job($event->connectionName, $event->job, 'processing'));
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

        $this->recorder->record([
            ...$this->job($connection, $job, $status),
            'duration_ms' => $start !== null ? round((microtime(true) - $start) * 1000, 3) : null,
            ...$extra,
        ]);

        if ($connection !== 'sync') {
            $this->recorder->capture(false);
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
