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

## Scrubbing

Lumberjack blanks passwords, tokens, cookies, authorization headers and the like before anything is stored. Add keys of your own to blank them before they leave the app:

```php
'scrub' => ['iban', 'cpr'],
```
