# What is recorded

Everything is grouped by a trace id: a request, a job, a command or a scheduled task gets one, and the queries, logs and exceptions it caused share it. In Lumberjack a trace shows all of them in order. A long-lived worker (Octane with Swoole, RoadRunner or FrankenPHP) gives every request its own trace too, and an exception carries the request being served; Octane is not required.

Every kind has its own section in the [configuration](configuration.md), with an `enabled` switch and `ignore` lists (most take `*` wildcards, see [Configuration](configuration.md#recording)). Publish the config to change them:

```bash
php artisan vendor:publish --tag=lumberjack-config
```

## Exceptions

Every exception your app reports, with:

- its frames, and a few lines of code around the app's own frames,
- the request (method, URL, route, headers), the signed-in user's id and the session keys you allow,
  all [scrubbed](#scrubbing) first,
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

### What is counted exactly, and how the rest is weighed

Sampling never hides how much traffic there is:

- **Every request is counted**, sampled or not: per method, route (the path when no route
  matched), domain and status, a count and the summed duration. Each batch the app hands the agent
  carries the counts since the last one as one `request_counts` event, so Lumberjack's
  request totals and throughput are exact. Requests the rules leave out (`requests.ignore`,
  `ignore_methods`, `ignore_status`) are not counted. At most 500 distinct rows go in one
  batch; more are added up in one `other` row.
- **Every run says how it was picked.** A request, job, command or scheduled run, and each
  of its queries and spans, carries `sample_rate` (the rate that applied) and `sampled`:
  `true` when the run was picked, `false` when it was kept only because it was slow or
  failed. Lumberjack weighs a sampled query by `1 / sample_rate` when it adds up calls and
  time, and leaves runs with `sampled: false` out of percentiles, which they would skew
  towards the slow end. An event without these fields counts as `sample_rate: 1`.

## Requests

Route, route name, domain, status, duration and peak memory. The domain is the host the
request was made to (`shop.example.com`, `api.example.com`), lowercased, with the port only
when it is not the default one, so the same route served on two domains is told apart. It
comes from the client's Host header: a malformed one is sent empty, and it is cut at 255
characters. The route name (`orders.show`) is sent when the route has one.

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

Queued, processing, processed, released and failed, each job in its own trace (a job on the `sync` connection stays in the trace of the request or command that ran it), with its attempt, queue, duration and, when it fails, its exception.

When a job is queued, its public properties are sent along as parameters: scalars as they are, models as `App\Models\Order#42`, dates as ISO 8601, arrays and collections cut at 10 items, anything else as its class name. Jobs that implement `ShouldBeEncrypted` send none, and the rest is [scrubbed](#scrubbing) like any other context: a `$password` property goes as `[filtered]`.

```php
'jobs' => [
    'ignore' => ['Laravel\Scout\Jobs\*'],
    'ignore_queues' => ['low'],
    'parameters' => true,
],
```

## Artisan commands

Every command with the arguments and options it was given, its exit code and its duration. A command it calls shares its trace, and so does a command a request, job or task runs with `Artisan::call()`: it is recorded as part of that run, which keeps its own trace and events. A non-zero exit becomes an issue in Lumberjack, unless the command's own exception already did.

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

## Maintenance

`artisan down` and `artisan up` tell Lumberjack the app is in maintenance, so the
downtime shows as planned. The start goes straight to Lumberjack rather than with the
next batch, as the app is going down; when Lumberjack cannot be reached, it goes through
the agent, which keeps it until Lumberjack is back. Sending never stops `down` or `up`.
Under `Lumberjack::fake()` nothing is sent; assert with `EventType::Maintenance`.

```php
'maintenance' => [
    'enabled' => true,
    'expected_minutes' => 15,  // sent with the start; null when unknown
],
```

## Spans

Besides queries, a request, job or command records what else it does, each on its trace
in Lumberjack, so the trace shows it in order with the queries and log lines.

| Kind | Recorded | Left out |
|---|---|---|
| `cache` | hit, miss, write and forget, with the key and store | the framework's own keys (`illuminate:*`, `framework/*`, cache locks, Lumberjack's own, Horizon, Pulse, Telescope) |
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

Secrets are blanked in the app, before anything reaches the agent, its spool or Lumberjack.
What is blanked becomes `[filtered]`. By default:

- **Sensitive keys**, wherever context is sent: request headers, log context, job
  parameters, command arguments and options, Laravel `Context`, span attributes.
  Keys match case-insensitively, `-` and `_` alike: `authorization`, `proxy-authorization`,
  `cookie`, `set-cookie`, `x-api-key`, `x-auth-token`, `x-csrf-token`, `x-xsrf-token`,
  `php-auth-pw`, `password`, `password_confirmation`, `passwd`, `secret`, `token`, `api_key`,
  `apikey`, `access_token`, `refresh_token`, `client_secret`, `card`, `card_number`, `cvv`,
  `cvc`, `iban`, `ssn`, `private_key`, `_token`, and any key containing `secret`,
  `password`, `token`, `api_key`, `apikey` or `private_key`.
- **Query strings**: every value in a URL (the request's, the one sent with an
  exception, and any URL inside a message, such as Guzzle's
  `` `GET https://api.example.com/x?api_key=[filtered]` resulted in ``) is blanked, the
  keys stay: `/reset?token=[filtered]&page=[filtered]`.
- **The session**: left out, except for the keys you allow.
- **Credentials in any string** (messages and context values): bearer and basic tokens,
  JWTs, the password in `scheme://user:password@host`, AWS access keys and Stripe secret
  keys.
- **Failed queries**: a `QueryException`'s message keeps the SQL with its `?`
  placeholders, never the values bound to it.

Queries are always sent without their bindings, and outgoing HTTP spans without their
query string. Objects in context are sent as what they are about (a model as
`App\Models\User#42`, a date as ISO 8601), taken when the event is recorded.

Change it in `config/lumberjack.php`:

```php
'scrub' => [
    'keys' => ['cpr', 'x-tenant-*'],    // extra keys, on top of the defaults
    'replace_defaults' => false,        // true: only your keys
    'patterns' => ['/\d{6}-\d{4}/'],    // regular expressions blanked in any string
    'session_keys' => ['locale'],       // session keys sent with an exception
    'query_keys' => ['page', 'sort'],   // query-string keys whose values are sent
],
```

The old shape, a list of extra keys (`'scrub' => ['iban', 'cpr']`), still works.
