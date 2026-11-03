# What is recorded

Everything is grouped by a trace id: a request, a job, a command or a scheduled task gets one, and the queries, logs and exceptions it caused share it. In Lumberjack a trace shows all of them in order.

Every kind has its own section in the [configuration](configuration.md), with an `enabled` switch and `ignore` lists that take `*` wildcards. Publish the config to change them:

```bash
php artisan vendor:publish --tag=lumberjack-config
```

## Exceptions

Every exception your app reports, with:

- its frames, and a few lines of code around the app's own frames,
- the request (method, URL, route, headers), the signed-in user's id and the session,
- Laravel's `Context`.

```php
'exceptions' => [
    'ignore' => [Illuminate\Auth\AuthenticationException::class], // and their subclasses
],
```

An exception that Laravel also logs is sent once, not again as a log line. A job's or a scheduled task's failure goes with that job or task.

## Sampling

A busy app does not need every run's queries to see where time goes. Each kind of run has
its own `sample_rate`, from 0 to 1: the share of requests, jobs, commands and scheduled
tasks whose queries and spans are recorded. A run that is not sampled is still recorded
itself (a job's or task's status, duration and failure always are; a request only when
it is slow, below), so the Jobs, Schedule and Commands pages stay complete and a missed
or failed task is always noticed.

A run is sampled whole or not at all, never query by query: an N+1, a trace and a share
of database time only make sense with every query of the run.

What sampling must not hide is kept anyway, each with a threshold in milliseconds you set
in your app's config:

- **A slow run**, sampled or not, is recorded with all its queries and spans:
  `requests.always_slower_than_ms` (1000), `jobs.always_slower_than_ms` (5000),
  `commands.always_slower_than_ms` (10000), `schedule.always_slower_than_ms` (10000).
- **A failed run** (a failed job, a command that exits with an error, a failed scheduled
  task) keeps its queries too.
- **A slow query** from any run is kept on its own: `queries.always_slower_than_ms` (500).

`0` turns a threshold off. A run that is not sampled holds at most 1,000 queries and spans
in memory until it knows whether it was slow.

```php
'requests' => ['sample_rate' => 0.25, 'always_slower_than_ms' => 1000],
'jobs' => ['sample_rate' => 0.25, 'always_slower_than_ms' => 5000],
'commands' => ['sample_rate' => 1.0, 'always_slower_than_ms' => 10000],
'schedule' => ['sample_rate' => 0.1, 'always_slower_than_ms' => 10000],
'queries' => ['always_slower_than_ms' => 500],
```

A command the scheduler runs takes the schedule's rate and threshold, also in its own
`php artisan` process.

## Requests

Route, status, duration and peak memory.

```php
'requests' => [
    'sample_rate' => 1.0,                          // share of requests timed, with their queries
    'ignore' => ['up', 'horizon*', 'telescope*'],  // route URIs or paths
    'ignore_methods' => ['OPTIONS', 'HEAD'],
    'ignore_status' => [404],                      // e.g. for a site crawlers hammer
],
```

## Queries

The SQL without its bindings, the connection, the duration, and the line in your app's code that ran it, so Lumberjack can show where a slow query or an N+1 comes from. Queries are recorded inside requests, jobs, commands and scheduled tasks, not outside them, so a worker polling for jobs adds nothing.

```php
'queries' => [
    'slower_than_ms' => 10,                // only queries at least this slow; 0 records all
    'ignore' => ['*information_schema*'],
    'ignore_connections' => ['audit'],
    'location' => true,                    // the app's line; costs a short backtrace per query
],
```

Lumberjack marks a query an **N+1** when one request or job runs it 5 times or more.

## Jobs

Queued, processing, processed, released and failed, each job in its own trace, with its attempt, queue, duration and, when it fails, its exception.

When a job is queued, its public properties are sent along as parameters: scalars as they are, models as `App\Models\Order#42`, dates as ISO 8601, arrays and collections cut at 10 items, anything else as its class name. Jobs that implement `ShouldBeEncrypted` send none, and Lumberjack scrubs the rest like any other context.

```php
'jobs' => [
    'ignore' => ['Laravel\Scout\Jobs\*'],
    'ignore_queues' => ['low'],
    'parameters' => true,
],
```

## Artisan commands

Every command with the arguments and options it was given, its exit code and its duration. A command it calls shares its trace. A non-zero exit becomes an issue in Lumberjack, unless the command's own exception already did.

Workers and other commands that never finish are left out by default:

```php
'commands' => [
    'ignore' => ['queue:work', 'horizon', 'schedule:run', 'schedule:work', 'lumberjack:*', /* ... */],
],
```

## Scheduled tasks

Every run of a scheduled task: started, finished, failed (an exception or a non-zero exit code) or skipped, with its cron expression and next run. Lumberjack shows them on the project's **Schedule** page and flags a task that did not run within 5 minutes of its time as missed.

```php
'schedule' => [
    'ignore' => ['artisan horizon:snapshot'],  // task names as Lumberjack shows them
],
```

A task that runs in the background is reported when it finishes, without a duration.

## Spans

Besides queries, a request, job or command records what else it does, each on its trace
in Lumberjack, so the trace shows it in order with the queries and log lines.

| Kind | Recorded | Left out |
|---|---|---|
| `cache` | hit, miss, write and forget, with the key and store | the framework's own keys (`illuminate:*`, cache locks, Horizon, Pulse) |
| `http` | outgoing calls through Laravel's HTTP client: method, host and path, status, duration | the query string, headers and bodies |
| `mail` | each message sent: the mailable class and how many recipients | addresses and the subject |
| `notification` | each notification sent: its class and channel | the notifiable |
| `redis` | each command, its first argument (usually the key) and duration. Off by default; there can be very many | the values |

```php
// config/lumberjack.php
'spans' => [
    'cache' => true,
    'http' => true,
    'mail' => true,
    'notifications' => true,
    'redis' => false,
    'ignore_cache_keys' => ['rate-limit:*'],
],
```

Each kind has an environment variable too: `LUMBERJACK_SPANS_CACHE`, `LUMBERJACK_SPANS_HTTP`,
`LUMBERJACK_SPANS_MAIL`, `LUMBERJACK_SPANS_NOTIFICATIONS` and `LUMBERJACK_SPANS_REDIS`.

## Scrubbing

Lumberjack blanks passwords, tokens, cookies, authorization headers and the like before anything is stored. Add keys of your own to blank them before they leave the app:

```php
'scrub' => ['iban', 'cpr'],
```
