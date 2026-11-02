<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

use Illuminate\Support\Str;
use Throwable;

/**
 * What gets recorded, from the requests, queries, jobs, exceptions and
 * schedule sections of the config. Every pattern takes * wildcards.
 */
final readonly class RecordingRules
{
    /**
     * @param  array<string, mixed>  $config  the lumberjack config
     */
    public static function fromConfig(array $config): self
    {
        $section = fn (string $name): array => is_array($config[$name] ?? null) ? $config[$name] : [];
        $list = fn (array $section, string $key): array => array_values(array_map('strval', array_filter((array) ($section[$key] ?? []), 'is_scalar')));

        $requests = $section('requests');
        $queries = $section('queries');
        $jobs = $section('jobs');
        $exceptions = $section('exceptions');
        $schedule = $section('schedule');
        $commands = $section('commands');

        return new self(
            requests: (bool) ($requests['enabled'] ?? true),
            sampleRate: (float) ($requests['sample_rate'] ?? 1.0),
            ignoreRoutes: $list($requests, 'ignore'),
            ignoreMethods: array_map('strtoupper', $list($requests, 'ignore_methods')),
            ignoreStatus: array_map('intval', $list($requests, 'ignore_status')),
            queries: (bool) ($queries['enabled'] ?? true),
            slowerThanMs: (float) ($queries['slower_than_ms'] ?? 0),
            ignoreQueries: $list($queries, 'ignore'),
            ignoreConnections: $list($queries, 'ignore_connections'),
            queryLocation: (bool) ($queries['location'] ?? true),
            jobs: (bool) ($jobs['enabled'] ?? true),
            ignoreJobs: $list($jobs, 'ignore'),
            ignoreQueues: $list($jobs, 'ignore_queues'),
            jobParameters: (bool) ($jobs['parameters'] ?? true),
            exceptions: (bool) ($exceptions['enabled'] ?? true),
            ignoreExceptions: $list($exceptions, 'ignore'),
            schedule: (bool) ($schedule['enabled'] ?? true),
            ignoreTasks: $list($schedule, 'ignore'),
            commands: (bool) ($commands['enabled'] ?? true),
            ignoreCommands: $list($commands, 'ignore'),
        );
    }

    /**
     * @param  list<string>  $ignoreRoutes
     * @param  list<string>  $ignoreMethods
     * @param  list<int>  $ignoreStatus
     * @param  list<string>  $ignoreQueries
     * @param  list<string>  $ignoreConnections
     * @param  list<string>  $ignoreJobs
     * @param  list<string>  $ignoreQueues
     * @param  list<string>  $ignoreExceptions
     * @param  list<string>  $ignoreTasks
     * @param  list<string>  $ignoreCommands
     */
    public function __construct(
        public bool $requests = true,
        public float $sampleRate = 1.0,
        public array $ignoreRoutes = [],
        public array $ignoreMethods = [],
        public array $ignoreStatus = [],
        public bool $queries = true,
        public float $slowerThanMs = 0,
        public array $ignoreQueries = [],
        public array $ignoreConnections = [],
        public bool $queryLocation = true,
        public bool $jobs = true,
        public array $ignoreJobs = [],
        public array $ignoreQueues = [],
        public bool $jobParameters = true,
        public bool $exceptions = true,
        public array $ignoreExceptions = [],
        public bool $schedule = true,
        public array $ignoreTasks = [],
        public bool $commands = true,
        public array $ignoreCommands = [],
    ) {
    }

    public function ignoresRoute(string $method, string $uri, string $path): bool
    {
        return in_array(strtoupper($method), $this->ignoreMethods, true)
            || Str::is($this->ignoreRoutes, $uri)
            || Str::is($this->ignoreRoutes, $path);
    }

    public function ignoresStatus(int $status): bool
    {
        return in_array($status, $this->ignoreStatus, true);
    }

    public function ignoresQuery(string $sql, string $connection, float $milliseconds): bool
    {
        return $milliseconds < $this->slowerThanMs
            || in_array($connection, $this->ignoreConnections, true)
            || Str::is($this->ignoreQueries, $sql);
    }

    public function ignoresJob(string $name, ?string $queue): bool
    {
        return Str::is($this->ignoreJobs, $name) || ($queue !== null && Str::is($this->ignoreQueues, $queue));
    }

    public function ignoresException(Throwable $e): bool
    {
        foreach ($this->ignoreExceptions as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    public function ignoresCommand(?string $name): bool
    {
        return $name === null || $name === '' || Str::is($this->ignoreCommands, $name);
    }

    public function ignoresTask(string $name): bool
    {
        return Str::is($this->ignoreTasks, $name);
    }
}
