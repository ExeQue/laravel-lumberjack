# Health checks

`php artisan lumberjack:health` runs a set of checks (the database answers, a disk can be written, the backup is fresh, ...) and sends each result to Lumberjack. Lumberjack keeps the history per check and alerts when one keeps failing. The package runs the command from your app's scheduler, so all you do is register checks.

## Three facade methods

| Method | Does | |
|---|---|---|
| `Lumberjack::healthChecks()` | Returns the registry: fluent built-ins, `->check()`, `->add()` | The only way to register a check |
| `Lumberjack::health('disk:s3')` | Returns a handle for one registered check: `->run()`, `->report()`, `->ok()`, `->warning()`, `->failed()` | Only fires a check; it never registers one. Its `->state()`, `->isHealthy()`, `->since()` and `->message()` read where Lumberjack says any check stands |
| `Lumberjack::healthStates()` | Returns every check Lumberjack knows for the app, by slug | Read only; see [Reading states](#reading-states) |

Registering and firing are separate so a call that means "fire this check" can never quietly register something, and registration stays in one place, a service provider. See [Firing a check from your code](#firing-a-check-from-your-code) and [Reading states](#reading-states) for the handle.

## Registering checks

In a service provider's `boot()`:

```php
use ExeQue\Lumberjack\Facades\Lumberjack;
use Illuminate\Support\Facades\Http;

public function boot(): void
{
    Lumberjack::healthChecks()
        ->database(maxLatencyMs: 200)
        ->cache()
        ->redis()
        ->disk('s3', slug: 'media-bucket')
        ->disk('local', warnAt: 80, failAt: 90)
        ->queue('redis', queue: 'emails', warnAt: 500, failAt: 2000)
        ->horizon()
        ->http('https://example.com/up', maxLatencyMs: 1000)
        ->debugMode()
        ->migrations()
        ->failedJobs(withinMinutes: 60)
        ->freshFile('backups', 'db', maxAgeMinutes: 1440)
        ->mail()
        ->check('stripe', fn () => Http::get('https://status.stripe.com/api/v2/status.json')->ok())
        ->add(new MyCustomCheck());
}
```

Registering costs a request nothing: the checks are only made and run when `lumberjack:health` runs.

Every method takes the check's required parameters first, then `degradeAfter`, `recoverAfter`, `downAfter`, the check's own options, `timeout` and last `slug`. Named arguments read best; positional ones are a shorthand, so `->disk('s3', 3, 5)` is disk `s3` with `degradeAfter: 3` and `recoverAfter: 5`, and `->disk('s3', 3, 5, 10)` adds `downAfter: 10`.

| Parameter | Default | |
|---|---|---|
| `degradeAfter` | `2` | Bad runs in a row (warnings or failures) before Lumberjack marks the check degraded |
| `recoverAfter` | `5` | Good runs in a row before a degraded or down check is ok again; until then it shows as recovering |
| `downAfter` | `5` | Failed runs in a row before it is down; a warning starts the count again, so warnings alone never take a check down |
| `timeout` | `10` | Seconds the check may take; past it the check failed. See [Timeouts](#timeouts) |
| `slug` | per check | The check's identity in Lumberjack. See [Slugs](#slugs) |

`downAfter` is never below `degradeAfter`: Lumberjack uses the larger of the two. So a check that only ever fails (a closure returning `false`) goes degraded after 2 failures and down after 5, instead of straight to down. Set `downAfter` to the same value as `degradeAfter` to go straight to down, as before `downAfter` existed:

```php
Lumberjack::healthChecks()->disk('s3', degradeAfter: 2, downAfter: 2);
```

The thresholds are sent with every result; a slug's thresholds set in Lumberjack's UI win over them.

### Closure checks

`->check($slug, $callback, $degradeAfter = 2, $recoverAfter = 5, $downAfter = 5, $timeout = 10)` runs the closure. Its parameters are resolved by the container. Return:

- `true` (or nothing) for ok, `false` for failed,
- or a `CheckResult` for a message, metrics or a warning:

```php
use ExeQue\Lumberjack\Health\CheckResult;

Lumberjack::healthChecks()->check('exports', function (ExportRepository $exports) {
    $stuck = $exports->stuck()->count();

    return $stuck > 0
        ? CheckResult::warning("{$stuck} exports stuck", ['stuck' => $stuck])
        : CheckResult::ok();
});
```

An exception fails the check, with the exception's message.

### Your own checks

A class implementing `ExeQue\Lumberjack\Contracts\HealthCheck`:

```php
use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;

final class LicenseServerCheck implements HealthCheck
{
    // slug(), degradeAfter(), recoverAfter(), downAfter() and timeout() from these parameters.
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $downAfter = 5,
        private readonly int $timeout = 10,
        private readonly ?string $slug = null,
    ) {
    }

    public function run(): CheckResult
    {
        return CheckResult::ok('Licenses valid', ['seats_left' => 12]);
    }
}
```

`CheckResult::ok()`, `::warning()` and `::failed()` each take an optional message and metrics (name to number). `CheckResult::threshold($value, $warnAt, $failAt, $message, $metrics)` picks one for a number: failed at `failAt`, a warning at `warnAt` (both inclusive, null for never), ok below. An exception thrown by a check fails it with the exception's message, or its class when the message is empty. With the trait, the slug defaults to the class name in kebab case (`license-server-check`).

### In config

`lumberjack.health.checks` takes the same checks, as a class or `[class, 'parameter' => value, ...]` made by the container:

```php
use ExeQue\Lumberjack\Health\Checks\DatabaseHealthCheck;
use ExeQue\Lumberjack\Health\Checks\DiskHealthCheck;

'health' => [
    'schedule' => '* * * * *',
    'one_server' => true,
    'checks' => [
        DatabaseHealthCheck::class,
        [DiskHealthCheck::class, 'disk' => 's3', 'slug' => 'media-bucket'],
    ],
],
```

`->add()` takes the same entries, one check per argument, so a list is spread:

```php
Lumberjack::healthChecks()->add(
    DatabaseHealthCheck::class,
    [DiskHealthCheck::class, 'disk' => 's3', 'slug' => 'media-bucket'],
    new LicenseServerCheck(),
);

Lumberjack::healthChecks()->add(...$checks);
```

A `[class, 'parameter' => value, ...]` array is always one check, never a list. Checks from config come first; a later check with the same slug replaces an earlier one, so code can override a config check.

## Slugs

The slug is the check's stable identity: Lumberjack keeps the history, alert state and display name by slug. Changing a slug starts a new check in Lumberjack. Set a display name for it in Lumberjack, not in the app.

A slug is 1 to 100 characters of `a-z`, `0-9`, `:`, `.`, `_` and `-`, starting with a letter or digit. An invalid slug given to `Lumberjack::healthChecks()` throws `ExeQue\Lumberjack\Exceptions\InvalidHealthCheckSlugException` when the check is registered. A bad entry in `lumberjack.health.checks` (an invalid slug, a class that does not exist, a parameter it does not take) never breaks the app's boot or scheduler: that check is skipped, and the problem is written once to PHP's error log and sent to Lumberjack as an error. Set your own with `slug:`:

```php
Lumberjack::healthChecks()->disk('s3', slug: 'media-bucket');
```

The defaults are listed with each check below. Names that come from config (a connection, a disk) are filled in, e.g. `database:mysql` for the default connection, and anything not allowed becomes `-` (`http:example.com-up`).

## Built-in checks

All in `ExeQue\Lumberjack\Health\Checks`, each with a method of the same name on `Lumberjack::healthChecks()`. Besides the common parameters above:

| Method | Class | Parameters | Default slug |
|---|---|---|---|
| `disk()` | `DiskHealthCheck` | `disk`, `warnAt`, `failAt` | `disk:<disk>` |
| `database()` | `DatabaseHealthCheck` | `connection`, `maxLatencyMs`, `warnAtConnections` | `database:<connection>` |
| `cache()` | `CacheHealthCheck` | `store` | `cache:<store>` |
| `redis()` | `RedisHealthCheck` | `connection` | `redis:<connection>` |
| `queue()` | `QueueHealthCheck` | `connection`, `queue`, `warnAt`, `failAt` | `queue:<connection>:<queue>` |
| `horizon()` | `HorizonHealthCheck` | | `horizon` |
| `http()` | `HttpHealthCheck` | `url`, `status` (200), `maxLatencyMs`, `method` (`GET`), `headers` | `http:<host><path>` |
| `debugMode()` | `DebugModeHealthCheck` | `environments` (`['production']`) | `debug-mode` |
| `migrations()` | `MigrationsHealthCheck` | `connection` | `migrations:<connection>` |
| `failedJobs()` | `FailedJobsHealthCheck` | `withinMinutes` (60), `warnAt` (1), `failAt` | `failed-jobs` |
| `freshFile()` | `FreshFileHealthCheck` | `disk`, `path`, `maxAgeMinutes`, `recursive` (false) | `fresh:<disk>:<path>` |
| `mail()` | `MailHealthCheck` | `mailer` | `mail:<mailer>` |

A null connection, store or mailer is the app's default. `warnAt` and `failAt` are inclusive and off when null.

- **disk**: writes a `.lumberjack-health-<random>` file, reads it back and deletes it; metric `latency_ms`. For a `local` disk also `used_percent` of the disk its root is on, a warning at `warnAt` and failed at `failAt`. Failed when the disk is not configured or a step fails.
- **database**: `select 1`; metric `latency_ms`, a warning above `maxLatencyMs`. It also reports what the server says about itself: `connections` (the server's open client connections, every database, as the limit is the server's), `max_connections` and `size_bytes` (this connection's database only) on PostgreSQL, MySQL and MariaDB, and the file's `size_bytes` on SQLite. A user without the rights to ask gives fewer figures; they never fail the check. `warnAtConnections` (a share, `0.8` is 80 %) warns when open connections reach that share of the limit.
- **cache**: puts, reads and forgets a random key; metric `latency_ms`.
- **redis**: `PING`; metric `latency_ms`.
- **queue**: the queue's size (metric `size`), a warning at `warnAt` and failed at `failAt`.
- **horizon**: failed when Horizon is not installed, no master supervisor runs or it is paused, as `horizon:status` sees it.
- **http**: a request with the check's timeout; failed on a connection error or another status than `status`, a warning above `maxLatencyMs`; metric `latency_ms`. `headers` are sent with it, for an endpoint behind a token or a virtual host:

  ```php
  Lumberjack::healthChecks()->http('https://api.internal/health', headers: [
      'Authorization' => 'Bearer '.config('services.api.health_token'),
  ]);
  ```

  They stay in the app; neither names nor values are reported to Lumberjack.
- **debugMode**: failed when `app.debug` is on in one of `environments`.
- **migrations**: failed while migrations are pending (the migration files against the ones that ran, like `migrate:status`), or when the migrations table does not exist; metric `pending`.
- **failedJobs**: jobs that failed within the last `withinMinutes`, through the app's failed job provider (`queue.failed`); metric `count`. Ok when failed jobs are not stored (driver `null`). The database providers are counted with a query; the others list every failed job.
- **freshFile**: the newest file directly under `path` (or anywhere below it with `recursive: true`) is younger than `maxAgeMinutes`, e.g. the last backup; metric `age_minutes`. Failed when there is none.
- **mail**: for an SMTP mailer, connects and logs in (EHLO, AUTH) and disconnects. Nothing is sent. Metric `latency_ms`. Other mailers (log, array, SES, Postmark, ...) are ok with a message saying they are not checked.

## Running

```bash
php artisan lumberjack:health
```

prints a table of every check (slug, status, message, duration) and sends the results through [the agent](agent.md), like everything else the app records: they need the agent running, and while `LUMBERJACK_ENABLED` is `false` it runs no checks and says so. It exits with `1` when a check failed (a warning does not), so a deploy script can stop on it:

```bash
php artisan migrate --force
php artisan lumberjack:health || exit 1
```

| Option | |
|---|---|
| `--check=<slug>` | Run only this check |
| `--no-fail` | Exit with `0` even when a check failed |

## Firing a check from your code

When your app notices a problem itself, for example a read from S3 that throws, it
does not have to wait for the next scheduled run. `Lumberjack::health($slug)` is a
handle for a registered check. Run the check now:

```php
use ExeQue\Lumberjack\Facades\Lumberjack;

try {
    $pdf = Storage::disk('s3')->get($path);
} catch (Throwable $e) {
    Lumberjack::health('disk:s3')->run();

    throw $e;
}
```

Or send what you found without running the check:

```php
Lumberjack::health('disk:s3')->failed("Reading {$path} failed: {$e->getMessage()}");
Lumberjack::health('disk:s3')->warning('Reading took 4 s', ['latency_ms' => 4000]);
Lumberjack::health('disk:s3')->ok('Reading works again');

// The same, with a CheckResult you built:
use ExeQue\Lumberjack\Health\CheckResult;

Lumberjack::health('disk:s3')->report(CheckResult::failed('Bucket gone'));
```

| Method | Returns | |
|---|---|---|
| `run()` | `?CheckResult` | Runs the check and sends its result; `null` within the cooldown |
| `report(CheckResult $result)` | `bool` | Sends a result without running the check; `false` within the cooldown |
| `ok($message, $metrics)` | `bool` | `report(CheckResult::ok(...))` |
| `warning($message, $metrics)` | `bool` | `report(CheckResult::warning(...))` |
| `failed($message, $metrics)` | `bool` | `report(CheckResult::failed(...))` |

Every result goes out straight away, with the check's `degradeAfter`, `recoverAfter`
and `downAfter`, and counts toward them like a scheduled one: one failure does not
mark the check degraded on its own, and an `ok()` the app saw counts toward
`recoverAfter`.

A busy code path can fail on every request, so each slug fires at most once per
`health.cooldown` seconds (30 by default; through the cache, across processes). Set
`LUMBERJACK_HEALTH_COOLDOWN=0` to turn the cooldown off. If the cache itself is down,
the check fires anyway. The slug is looked up when the handle is used: a slug that is
not registered throws an `InvalidArgumentException` from `run()`, `report()`, `ok()`,
`warning()` and `failed()`.

## Reading states

The app can ask where Lumberjack says a check stands, for example to hide an upload
button while `disk:s3` is down. This works for any slug Lumberjack knows for the app's
environment, whether it is registered here or not:

```php
use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\Health\HealthState;

if (! Lumberjack::health('disk:s3')->isHealthy()) {
    return back()->with('notice', 'Uploads are paused: '.Lumberjack::health('disk:s3')->message());
}

Lumberjack::health('payments-api')->state() === HealthState::Down;
```

| Method | Returns | |
|---|---|---|
| `state()` | `HealthState` | `Ok`, `Degraded`, `Down`, `Recovering`, `Stale`, `Pending`, or `Unknown` for a slug Lumberjack does not list (or no answer from it) |
| `isHealthy()` | `bool` | `true` for `Ok` only; a recovering check is still part of its outage |
| `since()` | `?CarbonImmutable` | When the check entered its state; for a stale one, its last result |
| `message()` | `?string` | The message of its latest result |

`HealthState` also has `isBad()`, true for `Degraded` and `Down`.
`Lumberjack::healthStates()` returns every check at once, keyed by slug, as
`HealthStateSnapshot`s (`slug`, `state`, `since`, `message`). Only checks the app
reports are listed; checks Lumberjack runs itself have no slug.

None of these throw. Without an answer from Lumberjack a check is `Unknown`.

### Where the states come from

The states are kept in the app's cache, and filled two ways:

- **By the agent.** Every heartbeat (once a minute) gets the states back, and the
  agent writes them into the cache. The agent is a separate process, so the store
  must be one it shares with the app: redis, database, memcached, or file when they
  run on the same server. The array store does not work.
- **By the app itself**, only when the agent has not written them for 150 seconds
  (two and a half heartbeats) and they are older than `health.states.ttl` (30
  seconds). Then the app fetches `GET /api/health` with the server token and a 0.5
  second timeout. Never inside a web request: that request reads the stored (stale)
  states at once, and the fetch runs after its response is sent, so a slow Lumberjack
  never holds up a page and `HealthStateChanged` listeners run after the response too.
  In a job or command the fetch runs right away. It happens at most once per ttl for
  everything sharing the cache, whether Lumberjack answered or not, so a server that is
  down is not asked on every request. Set `LUMBERJACK_HEALTH_STATES_PULL=false` to rely
  on the agent alone.

| Key | Env | Default | |
|---|---|---|---|
| `health.states.store` | `LUMBERJACK_HEALTH_STATES_STORE` | `null` | Cache store; the default store when null |
| `health.states.ttl` | `LUMBERJACK_HEALTH_STATES_TTL` | `30` | Seconds the stored states count as fresh |
| `health.states.pull` | `LUMBERJACK_HEALTH_STATES_PULL` | `true` | Fetch from Lumberjack when they are older |
| `health.states.memo` | `LUMBERJACK_HEALTH_STATES_MEMO` | `5` | Seconds a process keeps what it read, so many `state()` calls cost one cache read; `0` reads every time |

Asking is cheap. Lumberjack is asked at most once per ttl for everything that shares
the cache, whether it answered or not, and within one process the states are kept for
`memo` seconds, so a page that checks twenty slugs, or one slug in a loop, reads the
cache once. A cache that cannot be read counts as no states for `memo` seconds too,
so a cache outage is not retried on every call. A long-running process (a queue worker, Octane) sees a change up to `memo`
seconds late.

### Reacting to a change

Whenever new states are written, `ExeQue\Lumberjack\Events\HealthStateChanged` is
dispatched for each check whose state differs from the stored one. It carries `slug`,
`from` and `to` (both `HealthState`) and the new `snapshot`. The first time the app
hears of a slug the event comes `from` `Unknown`, so a check that is already down when
the app starts is reported too; a slug Lumberjack stops listing (archived) goes `to`
`Unknown`.

```php
use ExeQue\Lumberjack\Events\HealthStateChanged;
use Illuminate\Support\Facades\Event;

Event::listen(function (HealthStateChanged $event): void {
    if ($event->slug === 'payments-api' && $event->to->isBad()) {
        Cache::forever('checkout:paused', true);
    }
});
```

The event is dispatched in whichever process wrote the states: in the agent's process
when its heartbeat brought them, in the request, job or command that fetched them
otherwise. Keep listeners light, or make them queued (`ShouldQueue`): the agent does
not record anything itself, so logs or exceptions from a listener running in the
agent never reach Lumberjack, and a slow listener holds the agent up.

## Scheduling

With at least one check registered, the package adds `lumberjack:health` to your app's scheduler. It runs in the background, without overlapping, and on one server only. Its overlap lock expires after 5 minutes rather than Laravel's default day, so a run killed mid-way (a deploy, the OOM killer) holds up checks for minutes, not a day. The scheduler itself must run (`schedule:run` from cron, or `schedule:work`).

| Key | Env | Default | |
|---|---|---|---|
| `health.schedule` | `LUMBERJACK_HEALTH_SCHEDULE` | `* * * * *` | A cron expression; null or empty to schedule the command yourself |
| `health.one_server` | `LUMBERJACK_HEALTH_ONE_SERVER` | `true` | Run on one server only; needs a cache store with locks shared between servers (redis, database, memcached, dynamodb) |
| `health.checks` | | `[]` | Checks; see [In config](#in-config) |
| `health.cooldown` | `LUMBERJACK_HEALTH_COOLDOWN` | `30` | Seconds a check fired from your code waits before it may fire again; `0` turns it off. See [Firing a check from your code](#firing-a-check-from-your-code) |

The run is recorded as an ordinary [scheduled task](recording.md), so Lumberjack also notices when the scheduler stops running it.

## Timeouts

Each check gets `timeout` seconds; `0` turns its timeout off. With the `pcntl` extension (the CLI usually has it) a check is stopped when the time is up and fails with "Timed out after N s.". Without `pcntl` a check that hangs cannot be stopped: it fails once it returns late, and one that never returns holds the run up until `withoutOverlapping`'s lock expires. The HTTP check also passes its timeout to the request itself.
