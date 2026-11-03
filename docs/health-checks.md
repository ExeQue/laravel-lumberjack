# Health checks

`php artisan lumberjack:health` runs a set of checks (the database answers, a disk can be written, the backup is fresh, ...) and sends each result to Lumberjack. Lumberjack keeps the history per check and alerts when one keeps failing. The package runs the command from your app's scheduler, so all you do is register checks.

## Registering checks

In a service provider's `boot()`:

```php
use ExeQue\Lumberjack\Facades\Lumberjack;
use Illuminate\Support\Facades\Http;

public function boot(): void
{
    Lumberjack::health()
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

Every method takes the check's required parameters first, then `degradeAfter`, `recoverAfter`, the check's own options, `timeout` and last `slug`. Named arguments read best; positional ones are a shorthand, so `->disk('s3', 3, 5)` is disk `s3` with `degradeAfter: 3` and `recoverAfter: 5`.

| Parameter | Default | |
|---|---|---|
| `degradeAfter` | `2` | Failed runs in a row before Lumberjack marks the check failing |
| `recoverAfter` | `5` | Good runs in a row before it counts as healthy again |
| `timeout` | `10` | Seconds the check may take; past it the check failed. See [Timeouts](#timeouts) |
| `slug` | per check | The check's identity in Lumberjack. See [Slugs](#slugs) |

### Closure checks

`->check($slug, $callback, $degradeAfter = 2, $recoverAfter = 5, $timeout = 10)` runs the closure. Its parameters are resolved by the container. Return:

- `true` (or nothing) for ok, `false` for failed,
- or a `CheckResult` for a message, metrics or a warning:

```php
use ExeQue\Lumberjack\Health\CheckResult;

Lumberjack::health()->check('exports', function (ExportRepository $exports) {
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
    // slug(), degradeAfter(), recoverAfter() and timeout() from these parameters.
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
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

`CheckResult::ok()`, `::warning()` and `::failed()` each take an optional message and metrics (name to number). With the trait, the slug defaults to the class name in kebab case (`license-server-check`).

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

`Lumberjack::health([...])` takes the same list. Checks from config come first; a later check with the same slug replaces an earlier one, so code can override a config check.

## Slugs

The slug is the check's stable identity: Lumberjack keeps the history, alert state and display name by slug. Changing a slug starts a new check in Lumberjack. Set a display name for it in Lumberjack, not in the app.

A slug is 1 to 100 characters of `a-z`, `0-9`, `:`, `.`, `_` and `-`, starting with a letter or digit. An invalid slug throws `ExeQue\Lumberjack\Exceptions\InvalidHealthCheckSlugException` when the check is registered. Set your own with `slug:`:

```php
Lumberjack::health()->disk('s3', slug: 'media-bucket');
```

The defaults are listed with each check below. Names that come from config (a connection, a disk) are filled in, e.g. `database:mysql` for the default connection, and anything not allowed becomes `-` (`http:example.com-up`).

## Built-in checks

All in `ExeQue\Lumberjack\Health\Checks`, each with a method of the same name on `Lumberjack::health()`. Besides the common parameters above:

| Method | Class | Parameters | Default slug |
|---|---|---|---|
| `disk()` | `DiskHealthCheck` | `disk`, `warnAt`, `failAt` | `disk:<disk>` |
| `database()` | `DatabaseHealthCheck` | `connection`, `maxLatencyMs` | `database:<connection>` |
| `cache()` | `CacheHealthCheck` | `store` | `cache:<store>` |
| `redis()` | `RedisHealthCheck` | `connection` | `redis:<connection>` |
| `queue()` | `QueueHealthCheck` | `connection`, `queue`, `warnAt`, `failAt` | `queue:<connection>:<queue>` |
| `horizon()` | `HorizonHealthCheck` | | `horizon` |
| `http()` | `HttpHealthCheck` | `url`, `status` (200), `maxLatencyMs`, `method` (`GET`) | `http:<host><path>` |
| `debugMode()` | `DebugModeHealthCheck` | `environments` (`['production']`) | `debug-mode` |
| `migrations()` | `MigrationsHealthCheck` | `connection` | `migrations:<connection>` |
| `failedJobs()` | `FailedJobsHealthCheck` | `withinMinutes` (60), `warnAt` (1), `failAt` | `failed-jobs` |
| `freshFile()` | `FreshFileHealthCheck` | `disk`, `path`, `maxAgeMinutes`, `recursive` (false) | `fresh:<disk>:<path>` |
| `mail()` | `MailHealthCheck` | `mailer` | `mail:<mailer>` |

A null connection, store or mailer is the app's default. `warnAt` and `failAt` are inclusive and off when null.

- **disk**: writes a `.lumberjack-health-<random>` file, reads it back and deletes it; metric `latency_ms`. For a `local` disk also `used_percent` of the disk its root is on, a warning at `warnAt` and failed at `failAt`. Failed when the disk is not configured or a step fails.
- **database**: `select 1`; metric `latency_ms`, a warning above `maxLatencyMs`.
- **cache**: puts, reads and forgets a random key.
- **redis**: `PING`; metric `latency_ms`.
- **queue**: the queue's size (metric `size`), a warning at `warnAt` and failed at `failAt`.
- **horizon**: failed when Horizon is not installed, no master supervisor runs or it is paused, as `horizon:status` sees it.
- **http**: a request with the check's timeout; failed on a connection error or another status than `status`, a warning above `maxLatencyMs`; metric `latency_ms`.
- **debugMode**: failed when `app.debug` is on in one of `environments`.
- **migrations**: failed while migrations are pending (the migration files against the ones that ran, like `migrate:status`), or when the migrations table does not exist; metric `pending`.
- **failedJobs**: jobs that failed within the last `withinMinutes`, through the app's failed job provider (`queue.failed`); metric `count`. Ok when failed jobs are not stored (driver `null`). The database providers are counted with a query; the others list every failed job.
- **freshFile**: the newest file directly under `path` (or anywhere below it with `recursive: true`) is younger than `maxAgeMinutes`, e.g. the last backup; metric `age_minutes`. Failed when there is none.
- **mail**: for an SMTP mailer, connects and logs in (EHLO, AUTH) and disconnects. Nothing is sent. Other mailers (log, array, SES, Postmark, ...) are ok with a message saying they are not checked.

## Running

```bash
php artisan lumberjack:health
```

prints a table of every check (slug, status, message, duration) and sends the results. It exits with `1` when a check failed (a warning does not), so a deploy script can stop on it:

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
does not have to wait for the next scheduled run. Run the registered check now:

```php
use ExeQue\Lumberjack\Facades\Lumberjack;

try {
    $pdf = Storage::disk('s3')->get($path);
} catch (Throwable $e) {
    Lumberjack::health()->run('disk:s3');

    throw $e;
}
```

Or send what you found without running the check:

```php
use ExeQue\Lumberjack\Health\CheckResult;

Lumberjack::health()->report('disk:s3', CheckResult::failed("Reading {$path} failed: {$e->getMessage()}"));
```

Both take the slug of a registered check and send the result straight away, with that
check's `degradeAfter` and `recoverAfter`. One failed result does not mark the check
down on its own; it counts toward `degradeAfter` like a scheduled one.

A busy code path can fail on every request, so each slug fires at most once every 30
seconds (through the cache, across processes): `run()` then returns `null` and
`report()` returns `false`. If the cache itself is down, the check fires anyway. A slug
that is not registered throws an `InvalidArgumentException`.

## Scheduling

With at least one check registered, the package adds `lumberjack:health` to your app's scheduler. It runs in the background, without overlapping, and on one server only. The scheduler itself must run (`schedule:run` from cron, or `schedule:work`).

| Key | Env | Default | |
|---|---|---|---|
| `health.schedule` | `LUMBERJACK_HEALTH_SCHEDULE` | `* * * * *` | A cron expression; null or empty to schedule the command yourself |
| `health.one_server` | `LUMBERJACK_HEALTH_ONE_SERVER` | `true` | Run on one server only; needs a cache store with locks shared between servers (redis, database, memcached, dynamodb) |
| `health.checks` | | `[]` | Checks; see [In config](#in-config) |

The run is recorded as an ordinary [scheduled task](recording.md), so Lumberjack also notices when the scheduler stops running it.

## Timeouts

Each check gets `timeout` seconds. With the `pcntl` extension (the CLI usually has it) a check is stopped when the time is up and fails with "Timed out after N s.". Without `pcntl` a check that hangs cannot be stopped: it fails once it returns late, and one that never returns holds the run up until `withoutOverlapping`'s lock expires. The HTTP check also passes its timeout to the request itself.
