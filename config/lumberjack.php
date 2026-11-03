<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Release\ComposerReleaseResolver;
use ExeQue\Lumberjack\Release\GitReleaseResolver;

return [

    /*
     * Turns all recording off when false. The agent still runs.
     */
    'enabled' => env('LUMBERJACK_ENABLED', true),

    /*
     * Base URL of the Lumberjack server, e.g. https://lumberjack.example.com.
     */
    'url' => rtrim((string) env('LUMBERJACK_URL', ''), '/'),

    /*
     * Server token (secret). Only the agent uses it.
     */
    'token' => env('LUMBERJACK_TOKEN'),

    /*
     * Public key for the browser script. Leave empty to render no script.
     */
    'public_key' => env('LUMBERJACK_PUBLIC_KEY'),

    'environment' => env('LUMBERJACK_ENVIRONMENT', env('APP_ENV', 'production')),

    'release' => env('LUMBERJACK_RELEASE'),

    /*
     * Works out the release when LUMBERJACK_RELEASE is empty: the first of these that
     * finds one. Git reads the commit from .git; Composer the commit vendor was
     * installed from, which survives a deploy without .git. A deploy can also write
     * the release to a file:
     *
     *     [\ExeQue\Lumberjack\Release\FileReleaseResolver::class, 'file' => 'REVISION'],
     *
     * Each entry is a class implementing ExeQue\Lumberjack\Contracts\ReleaseResolver,
     * or [class, 'parameter' => value, ...] made by the container (it also gets the
     * app's root as path). A single entry works without the list; null sends none.
     */
    'release_resolver' => [
        GitReleaseResolver::class,
        ComposerReleaseResolver::class,
    ],

    /*
     * Whether the browser script says hello in the console. Off unless the
     * app runs with APP_DEBUG.
     */
    'announce' => (bool) env('LUMBERJACK_ANNOUNCE', env('APP_DEBUG', false)),

    'agent' => [
        'host' => env('LUMBERJACK_AGENT_HOST', '127.0.0.1'),
        'port' => (int) env('LUMBERJACK_AGENT_PORT', 2407),

        // What Lumberjack lists this agent as, e.g. "web-1" or "queue"; the host when empty.
        'name' => env('LUMBERJACK_AGENT_NAME'),

        // Seconds between batches sent to Lumberjack.
        'interval' => 5,

        // Where failed batches wait for a retry, and how much they may take.
        'spool_path' => storage_path('lumberjack/spool'),
        'spool_max_bytes' => 100 * 1024 * 1024,

        // Past this much memory (MB) the agent sends what it has and exits, so
        // Supervisor or systemd starts a fresh one. 0 turns the limit off.
        'memory_limit' => (int) env('LUMBERJACK_AGENT_MEMORY', 128),
    ],

    /*
     * Queues whose size the agent sends with its heartbeat every minute, so Lumberjack
     * sees a backlog grow even when no worker runs: "connection:queue", or a queue on
     * the default connection, e.g. ['default', 'redis:emails']. Empty: the default
     * connection's default queue.
     */
    'queues' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('LUMBERJACK_QUEUES', ''))))),

    /*
     * What is recorded. Every "ignore" list takes * wildcards.
     */
    'requests' => [
        'enabled' => (bool) env('LUMBERJACK_REQUESTS', true),

        // Share of requests (0 to 1) whose timing and queries are recorded.
        'sample_rate' => (float) env('LUMBERJACK_SAMPLE_RATE', 1.0),

        // Route URIs or paths.
        'ignore' => ['up', 'horizon*', 'telescope*', '_debugbar*'],

        'ignore_methods' => ['OPTIONS', 'HEAD'],

        // Status codes, e.g. [404] for a site that crawlers hammer.
        'ignore_status' => [],
    ],

    'queries' => [
        'enabled' => (bool) env('LUMBERJACK_QUERIES', true),

        // Only queries at least this slow, in milliseconds. 0 records all.
        'slower_than_ms' => (float) env('LUMBERJACK_QUERIES_SLOWER_THAN_MS', 0),

        // SQL, e.g. '*information_schema*'.
        'ignore' => [],

        'ignore_connections' => [],

        // Record the line in the app's code that ran each query, for slow
        // queries and N+1s. Costs a short backtrace per query.
        'location' => (bool) env('LUMBERJACK_QUERY_LOCATION', true),
    ],

    'jobs' => [
        'enabled' => (bool) env('LUMBERJACK_JOBS', true),

        // Job classes, e.g. 'Laravel\Scout\Jobs\*'.
        'ignore' => [],

        'ignore_queues' => [],

        // Send the job's public properties along when it is queued: scalars as
        // they are, models as Class#id, anything else as its class name.
        'parameters' => (bool) env('LUMBERJACK_JOB_PARAMETERS', true),
    ],

    'exceptions' => [
        'enabled' => (bool) env('LUMBERJACK_EXCEPTIONS', true),

        // Exception classes, and their subclasses.
        'ignore' => [
            // Illuminate\Auth\AuthenticationException::class,
        ],
    ],

    'commands' => [
        'enabled' => (bool) env('LUMBERJACK_COMMANDS', true),

        // Command names. Workers and other long-running commands are left out,
        // as they would never finish.
        'ignore' => [
            'queue:work', 'queue:listen', 'horizon', 'horizon:*', 'schedule:run', 'schedule:work',
            'schedule:finish', 'lumberjack:*', 'reverb:start', 'octane:*', 'serve', 'tinker', 'pail',
            'list', 'help', 'package:discover', 'vendor:publish',
        ],
    ],

    /*
     * What else a request, job or command does, each on its trace in Lumberjack:
     * cache reads and writes, outgoing HTTP calls, mail and notifications sent,
     * and (off by default, as there can be very many) Redis commands.
     */
    'spans' => [
        'cache' => (bool) env('LUMBERJACK_SPANS_CACHE', true),
        'http' => (bool) env('LUMBERJACK_SPANS_HTTP', true),
        'mail' => (bool) env('LUMBERJACK_SPANS_MAIL', true),
        'notifications' => (bool) env('LUMBERJACK_SPANS_NOTIFICATIONS', true),
        'redis' => (bool) env('LUMBERJACK_SPANS_REDIS', false),

        // Cache keys, e.g. 'illuminate:*'. Framework bookkeeping is left out already.
        'ignore_cache_keys' => [],
    ],

    'schedule' => [
        'enabled' => (bool) env('LUMBERJACK_SCHEDULE', true),

        // Task names as Lumberjack shows them, e.g. 'artisan horizon:snapshot'.
        'ignore' => [],
    ],

    /*
     * Health checks lumberjack:health runs and reports, e.g. from the scheduler. Each
     * entry is a class implementing ExeQue\Lumberjack\Contracts\HealthCheck, or
     * [class, 'parameter' => value, ...] made by the container:
     *
     *     \ExeQue\Lumberjack\Health\Checks\DatabaseHealthCheck::class,
     *     [\ExeQue\Lumberjack\Health\Checks\DiskHealthCheck::class, 'disk' => 's3'],
     *
     * Lumberjack::healthChecks() registers more from a service provider. The schedule is a
     * cron expression; null leaves scheduling to you. one_server needs a cache store
     * that supports locks shared between servers. cooldown is how many seconds a
     * check fired from the app with Lumberjack::health($slug) waits before it may
     * fire again; 0 turns it off.
     */
    'health' => [
        'schedule' => env('LUMBERJACK_HEALTH_SCHEDULE', '* * * * *'),
        'one_server' => (bool) env('LUMBERJACK_HEALTH_ONE_SERVER', true),
        'cooldown' => (int) env('LUMBERJACK_HEALTH_COOLDOWN', 30),
        'checks' => [],

        // Reading back where Lumberjack says the checks stand (Lumberjack::health($slug)->state()).
        // The agent writes them into this cache store every minute, so the store must be one the
        // agent and the app share (redis, database, or file on the same server; not array).
        // States older than ttl seconds are fetched from Lumberjack, at most once per ttl;
        // pull false leaves them to the agent alone. null is the default store.
        'states' => [
            'store' => env('LUMBERJACK_HEALTH_STATES_STORE'),
            'ttl' => (int) env('LUMBERJACK_HEALTH_STATES_TTL', 30),
            'pull' => (bool) env('LUMBERJACK_HEALTH_STATES_PULL', true),
            // Seconds a process keeps what it read, so many state() calls cost one cache read; 0 reads every time.
            'memo' => (int) env('LUMBERJACK_HEALTH_STATES_MEMO', 5),
        ],
    ],

    /*
     * Lumberjack can POST alerts to this app (a webhook alert channel). Turned on,
     * the package answers on this path and runs the handlers registered with
     * Lumberjack::on() and Lumberjack::onException(). A request without a valid
     * signature gets a 404, as if the path did not exist.
     */
    'webhook' => [
        'enabled' => (bool) env('LUMBERJACK_WEBHOOK_ENABLED', false),
        'secret' => env('LUMBERJACK_WEBHOOK_SECRET'),
        'path' => env('LUMBERJACK_WEBHOOK_PATH', 'lumberjack/webhook'),
    ],

    /*
     * Where the app's own code lives, for code lines and in-app frames.
     * Leave it null for base_path().
     */
    'base_path' => null,

    /*
     * Extra context keys to blank before anything leaves the app. The server
     * scrubs passwords, tokens, cookies and the like on its own as well.
     */
    'scrub' => [],
];
