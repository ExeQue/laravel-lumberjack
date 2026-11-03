---
name: lumberjack-development
description: "Use when setting up or changing Lumberjack monitoring in a Laravel app with exeque/laravel-lumberjack: installing it, health checks (built-in, custom, closure, firing from code, reading states), release resolvers, reacting to alerts with webhook handlers (Lumberjack::on, Lumberjack::onException), what is recorded and ignored (recording rules, spans, sampling), log channels with the lumberjack driver, the browser script (@lumberjack), running and deploying the agent, and testing an app that uses the package."
---

# Lumberjack development

## When to use this skill

- Installing Lumberjack in an app, or changing its `.env` keys or `config/lumberjack.php`.
- Adding, changing or testing health checks, or reading their state to adapt the app.
- Reacting to Lumberjack alerts in the app (webhooks).
- Leaving things out of what is recorded, or recording more (spans, logs).
- Running the agent in production (Supervisor, systemd, Docker, Kubernetes) or after a deploy.

The package docs: https://exeque.github.io/laravel-lumberjack/docs/

## How it works

The app never talks to Lumberjack while it records. It hands each batch to a local agent (`php artisan lumberjack:agent`) on `127.0.0.1:2407` with a 50 ms connect timeout and drops it when no agent answers. The agent sends batches on every 5 seconds, spools them on disk while Lumberjack is down, and sends a heartbeat every minute. Nothing in the package throws into a request, job or command while recording. Do not add try/catch, retries or your own HTTP calls to Lumberjack.

## Install

```bash
composer require exeque/laravel-lumberjack
php artisan lumberjack:install
```

`lumberjack:install` verifies the URL and token with a heartbeat, writes `.env` (and `.env.example` with empty values), puts `@lumberjack` before `</head>` in the chosen layouts, generates `app/Providers/LumberjackServiceProvider.php` with health checks and registers it in `bootstrap/providers.php`, suggests `LUMBERJACK_QUEUES`, and prints how to run the agent. Running it again changes nothing that is set up. Non-interactive:

```bash
php artisan lumberjack:install --no-interaction --url=https://lumberjack.example.com --token=lj_srv_... --key=pk_... \
    --checks=database,cache,disk:s3,queue:redis:emails,failed-jobs --layout=resources/views/app.blade.php --queues=default,redis:emails
```

`--checks` takes `database`, `cache`, `redis`, `disk:<disk>`, `queue`, `queue:<connection>:<queue>`, `horizon`, `failed-jobs`, `debug-mode`, `migrations`, `mail` (and `database:<connection>`, `cache:<store>`, `mail:<mailer>`). `--no-verify` skips the check with Lumberjack, `--force` replaces existing values and files without asking.

The keys:

```dotenv
LUMBERJACK_URL=https://lumberjack.example.com
LUMBERJACK_TOKEN=lj_srv_...       # server token; only the agent uses it
LUMBERJACK_PUBLIC_KEY=pk_...      # browser errors; without it @lumberjack renders nothing
LUMBERJACK_ENVIRONMENT=production # defaults to APP_ENV
LUMBERJACK_RELEASE=               # optional; resolved from .git or vendor when empty
LUMBERJACK_ENABLED=true           # false turns all recording off
```

Publish the config with `php artisan vendor:publish --tag=lumberjack-config`.

## Health checks

Three facade methods, all on `ExeQue\Lumberjack\Facades\Lumberjack`:

- `Lumberjack::healthChecks()`: the registry, the only way to register a check. Call it in a service provider's `boot()`.
- `Lumberjack::health('slug')`: a handle to fire a registered check (`run()`, `report()`, `ok()`, `warning()`, `failed()`) or read any check's state (`state()`, `isHealthy()`, `since()`, `message()`). It never registers.
- `Lumberjack::healthStates()`: every check Lumberjack knows for the app, keyed by slug, as `HealthStateSnapshot`s (`slug`, `state`, `since`, `message`).

With at least one check registered, the package schedules `lumberjack:health` every minute (`withoutOverlapping`, `onOneServer`, in the background). The scheduler must run.

```php
use ExeQue\Lumberjack\Facades\Lumberjack;

public function boot(): void
{
    Lumberjack::healthChecks()
        ->database(maxLatencyMs: 200)
        ->cache()
        ->disk('s3', slug: 'media-bucket')
        ->queue('redis', queue: 'emails', warnAt: 500, failAt: 2000)
        ->failedJobs(withinMinutes: 60)
        ->freshFile('backups', 'db', maxAgeMinutes: 1440)
        ->add(LicenseServerCheck::class); // made by the container
}
```

### Built-in checks

Every method takes its required parameters first, then `degradeAfter` (2), `recoverAfter` (5), `downAfter` (5), the check's own options, `timeout` (10 seconds) and last `slug`. Use named arguments for anything after the first parameter. A null connection, store or mailer is the app's default. `warnAt` and `failAt` are inclusive, off when null.

| Method, parameters in order | Default slug | Checks |
|---|---|---|
| `disk(string $disk, ..., ?int $warnAt, ?int $failAt, ...)` | `disk:<disk>` | write, read, delete a file; for a local disk `used_percent` against warnAt/failAt |
| `database(?string $connection, ..., ?int $maxLatencyMs, ...)` | `database:<connection>` | `select 1`, warning above maxLatencyMs |
| `cache(?string $store, ...)` | `cache:<store>` | put, get, forget |
| `redis(?string $connection, ...)` | `redis:<connection>` | `PING` |
| `queue(?string $connection, ..., ?string $queue, ?int $warnAt, ?int $failAt, ...)` | `queue:<connection>:<queue>` | the queue's size |
| `horizon(...)` | `horizon` | Horizon installed, running, not paused |
| `http(string $url, ..., int $status = 200, ?int $maxLatencyMs, string $method = 'GET', ...)` | `http:<host><path>` | status and latency |
| `debugMode(..., array $environments = ['production'], ...)` | `debug-mode` | `app.debug` off in those environments |
| `migrations(?string $connection, ...)` | `migrations:<connection>` | no pending migrations |
| `failedJobs(..., int $withinMinutes = 60, int $warnAt = 1, ?int $failAt, ...)` | `failed-jobs` | failed jobs within the window |
| `freshFile(string $disk, string $path, int $maxAgeMinutes, ..., bool $recursive = false, ...)` | `fresh:<disk>:<path>` | newest file younger than maxAgeMinutes (backups) |
| `mail(?string $mailer, ...)` | `mail:<mailer>` | SMTP connects and logs in; other transports are ok, unchecked |

`...` stands for `degradeAfter, recoverAfter, downAfter` before the options and `timeout, slug` after them. The same checks go in `config('lumberjack.health.checks')` as a class or `[Class::class, 'parameter' => value]`. `add()` is variadic: one check per argument (an instance, a class, or such an array), so spread a list with `->add(...$checks)`. A later check with the same slug replaces an earlier one.

### Thresholds

- `degradeAfter`: bad runs (warnings or failures) in a row before the check is degraded.
- `downAfter`: failed runs in a row before it is down; a warning restarts the count. Never below degradeAfter; set both equal to go straight to down.
- `recoverAfter`: good runs in a row before it is ok again; until then it is recovering.

Thresholds set in Lumberjack's UI for a slug win over the ones in code.

### Slugs

A slug is the check's identity in Lumberjack (history, alerts, display name). Changing one starts a new check. 1 to 100 characters of `a-z 0-9 : . _ -`, starting with a letter or digit; an invalid slug throws `ExeQue\Lumberjack\Exceptions\InvalidHealthCheckSlugException` at registration. Set the display name in Lumberjack, not in code.

### Closure checks

`->check(string $slug, Closure $callback, int $degradeAfter = 2, int $recoverAfter = 5, int $downAfter = 5, int $timeout = 10)`. Parameters are resolved by the container. Return `true` or nothing for ok, `false` for failed, or a `CheckResult`; an exception fails the check with its message.

```php
use ExeQue\Lumberjack\Health\CheckResult;

Lumberjack::healthChecks()->check('exports', function (ExportRepository $exports) {
    $stuck = $exports->stuck()->count();

    return $stuck > 0 ? CheckResult::warning("{$stuck} exports stuck", ['stuck' => $stuck]) : CheckResult::ok();
});
```

### Custom check classes

Implement `ExeQue\Lumberjack\Contracts\HealthCheck`; the `ConfiguresHealthCheck` trait provides `slug()`, `degradeAfter()`, `recoverAfter()`, `downAfter()` and `timeout()` from constructor properties with those names. Its slug defaults to the class name in kebab case (`license-server-check`).

```php
use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;

final class LicenseServerCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly LicenseClient $client,
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $downAfter = 5,
        private readonly int $timeout = 10,
        private readonly ?string $slug = null,
    ) {
    }

    public function run(): CheckResult
    {
        $seats = $this->client->seatsLeft();

        return $seats < 5
            ? CheckResult::warning("{$seats} seats left", ['seats_left' => $seats])
            : CheckResult::ok('Licenses valid', ['seats_left' => $seats]);
    }
}
```

`CheckResult::ok()`, `::warning()` and `::failed()` take `?string $message` and `array $metrics` (name to number). `CheckResult::threshold($value, $warnAt, $failAt, $message, $metrics)` picks ok, warning or failed from a value against inclusive thresholds.

### Firing a check from code

When the app notices a problem itself, send it now instead of waiting for the next run:

```php
try {
    $pdf = Storage::disk('s3')->get($path);
} catch (Throwable $e) {
    Lumberjack::health('disk:s3')->run(); // runs the check, sends the result; null within the cooldown
    // or send what you saw, without running it:
    Lumberjack::health('disk:s3')->failed("Reading {$path} failed: {$e->getMessage()}");

    throw $e;
}

Lumberjack::health('disk:s3')->warning('Reading took 4 s', ['latency_ms' => 4000]);
Lumberjack::health('disk:s3')->ok('Reading works again');
Lumberjack::health('disk:s3')->report(CheckResult::failed('Bucket gone'));
```

Each counts toward the thresholds like a scheduled run. A slug fires at most once per `health.cooldown` seconds (`LUMBERJACK_HEALTH_COOLDOWN`, 30; 0 turns it off), through the cache. The slug must be registered: `run()`, `report()`, `ok()`, `warning()` and `failed()` throw `InvalidArgumentException` for an unknown one.

### Reading states

Works for any slug Lumberjack knows for the app's environment, registered here or not. None of these throw; without an answer a check is `Unknown`.

```php
use ExeQue\Lumberjack\Health\HealthState;

if (! Lumberjack::health('disk:s3')->isHealthy()) {
    return back()->with('notice', 'Uploads are paused: '.Lumberjack::health('disk:s3')->message());
}

Lumberjack::health('payments-api')->state() === HealthState::Down;
Lumberjack::health('payments-api')->since(); // ?CarbonImmutable
```

`HealthState`: `Ok`, `Degraded`, `Down`, `Recovering`, `Stale`, `Pending`, `Unknown`. `isHealthy()` is true for `Ok` only; `isBad()` for `Degraded` and `Down`.

States live in the cache. The agent writes them every heartbeat, so the store (`LUMBERJACK_HEALTH_STATES_STORE`, the default store when empty) must be shared by app and agent: redis, database, memcached, or file on one server, never array. When they are older than `health.states.ttl` (30 s) a read fetches them from Lumberjack, at most once per ttl for everything sharing the cache, with a 2 s timeout; `LUMBERJACK_HEALTH_STATES_PULL=false` leaves it to the agent. A process keeps what it read for `health.states.memo` seconds (5), so many reads cost one cache read.

When the states change, `ExeQue\Lumberjack\Events\HealthStateChanged` (`slug`, `from`, `to`, `snapshot`) is dispatched per changed check, from `Unknown` the first time. It may run in the agent process, so keep listeners light or queued:

```php
use ExeQue\Lumberjack\Events\HealthStateChanged;

Event::listen(function (HealthStateChanged $event): void {
    if ($event->slug === 'payments-api' && $event->to->isBad()) {
        Cache::forever('checkout:paused', true);
    }
});
```

## Releases

`LUMBERJACK_RELEASE` sets the release. When empty, `release_resolver` in `config/lumberjack.php` works it out once per process: a list is tried in order (it becomes a `StackReleaseResolver`), a single entry works without the list, `null` sends none. All resolvers are in `ExeQue\Lumberjack\Release`.

```php
use ExeQue\Lumberjack\Release\ComposerReleaseResolver;
use ExeQue\Lumberjack\Release\FileReleaseResolver;
use ExeQue\Lumberjack\Release\GitReleaseResolver;

'release_resolver' => [
    [FileReleaseResolver::class, 'file' => 'latest-release'], // first line of a file the deploy writes; REVISION by default
    GitReleaseResolver::class,      // the commit in .git, read without running git
    ComposerReleaseResolver::class, // the commit vendor was installed from
],
```

A custom resolver implements `ExeQue\Lumberjack\Contracts\ReleaseResolver` (`resolve(): ?string`); the container makes it with the entry's parameters and passes the app's root as `path` when the constructor takes one.

## Reacting to alerts (webhooks)

Lumberjack posts alerts to the app when a webhook channel points at it. Turn on the route:

```dotenv
LUMBERJACK_WEBHOOK_ENABLED=true
LUMBERJACK_WEBHOOK_SECRET=whsec_...
LUMBERJACK_WEBHOOK_PATH=lumberjack/webhook
```

Register handlers in a service provider's `boot()`:

```php
use ExeQue\Lumberjack\Webhooks\Alert;
use ExeQue\Lumberjack\Webhooks\Status;

Lumberjack::onException(PaymentGatewayException::class, [Status::New, Status::Reopened, Status::Escalating], function (Alert $alert) {
    StatusBanner::show('Payments are slow right now');
});

Lumberjack::on(Status::Resolved, fn (Alert $alert) => StatusBanner::hideFor($alert->title));
```

`Status`: `New`, `Reopened`, `Escalating`, `Resolved`, `Ignored`, `Note`. Exception classes match subclasses. `Alert` has `status`, `issueId`, `reference`, `title`, `message`, `url`, `environment`, `burst`, `note`, `by`, `payload` and `isException($class)`. Handlers run inside the webhook request: dispatch a job for anything slow. Every alert also fires `ExeQue\Lumberjack\Webhooks\AlertReceived`. The route is outside the `web` group (no session, no CSRF); a bad or old (over 5 minutes) signature gets a 404.

## What is recorded

Exceptions, requests, queries (with the app line that ran them), jobs, artisan commands and scheduled tasks, grouped by trace id. Each section of `config/lumberjack.php` has `enabled` and `ignore` lists with `*` wildcards:

```php
'requests' => ['sample_rate' => 0.25, 'ignore' => ['up', 'horizon*'], 'ignore_status' => [404]],
'queries' => ['slower_than_ms' => 10, 'ignore' => ['*information_schema*'], 'ignore_connections' => ['audit']],
'jobs' => ['ignore' => ['Laravel\Scout\Jobs\*'], 'ignore_queues' => ['low'], 'parameters' => true],
'exceptions' => ['ignore' => [Illuminate\Auth\AuthenticationException::class]], // and subclasses
'commands' => ['ignore' => ['queue:work', 'horizon', 'schedule:run', 'lumberjack:*']],
'schedule' => ['ignore' => ['artisan horizon:snapshot']],
'scrub' => ['iban'], // extra context keys blanked before anything leaves the app
```

Sampling: `requests`, `jobs`, `commands` and `schedule` each take a `sample_rate` (0 to 1), the share of runs whose queries and spans are recorded; a run is sampled whole, never query by query. Every job, command and scheduled run is still recorded itself. Slow and failed runs keep everything anyway via `always_slower_than_ms` per section (defaults 1000 / 5000 / 10000 / 10000), and `queries.always_slower_than_ms` (500) keeps a slow query from any run:

```php
'requests' => ['sample_rate' => 0.25, 'always_slower_than_ms' => 1000],
'schedule' => ['sample_rate' => 0.1, 'always_slower_than_ms' => 10000],
'queries' => ['always_slower_than_ms' => 500],
```

Spans on each trace: `cache`, `http` (Laravel HTTP client), `mail`, `notifications` on by default, `redis` off (`LUMBERJACK_SPANS_REDIS=true`); `spans.ignore_cache_keys` takes wildcards. Env switches: `LUMBERJACK_REQUESTS`, `LUMBERJACK_QUERIES`, `LUMBERJACK_JOBS`, `LUMBERJACK_EXCEPTIONS`, `LUMBERJACK_COMMANDS`, `LUMBERJACK_SCHEDULE`, `LUMBERJACK_SPANS_*`, `LUMBERJACK_SAMPLE_RATE`, `LUMBERJACK_JOBS_SAMPLE_RATE`, `LUMBERJACK_COMMANDS_SAMPLE_RATE`, `LUMBERJACK_SCHEDULE_SAMPLE_RATE`.

## Log channels

```php
// config/logging.php
'payments' => ['driver' => 'lumberjack', 'channel' => 'payments', 'level' => 'info'],
'stack' => ['driver' => 'stack', 'channels' => ['daily', 'lumberjack']], // everything the app logs
```

`channel` is the log channel in Lumberjack (`default` when left out or unknown there). Lines at `error` and above become issues; a reported exception is not sent again as a log line.

## Browser errors

`@lumberjack` in the `<head>` of the layout renders `<script src="{LUMBERJACK_URL}/sdk/v1.js" data-key data-environment data-release data-user data-trace data-announce defer>`: the public key, environment, release, signed-in user id, the request's trace id and whether to announce itself in the console (`LUMBERJACK_ANNOUNCE`, defaults to `APP_DEBUG`). Without a public key it renders nothing. The page's origin must be in the project's allowed origins in Lumberjack. From JavaScript:

```js
window.Lumberjack.captureException(error, { cart_id: 42 });
window.Lumberjack.setUser(userId);
window.Lumberjack.init({ ignore: [/^AbortError/, 'Load failed'] });
```

## The agent

Run one agent per app per server under a process manager; it reads the app's `.env`.

```ini
[program:lumberjack-agent]
command=php /var/www/app/artisan lumberjack:agent
autostart=true
autorestart=true
stopwaitsecs=15
user=www-data
```

- Options: `--name` (`LUMBERJACK_AGENT_NAME`), `--host`/`--port` (`LUMBERJACK_AGENT_HOST`, `LUMBERJACK_AGENT_PORT`, 2407), `--memory` (MB, `LUMBERJACK_AGENT_MEMORY`, 128; it exits past it for a fresh one).
- Several apps on one server: each its own agent and port.
- Docker Compose: a separate service from the app's image running `php artisan lumberjack:agent --host=0.0.0.0`, and `LUMBERJACK_AGENT_HOST=<service name>` in the app. Kubernetes: a sidecar container in the app's pod (shares 127.0.0.1).
- After a deploy: `php artisan lumberjack:terminate` (next to `queue:restart`), so it restarts on the new code.
- Unsent batches wait in `storage/lumberjack/spool` (100 MB at most) and are retried in order.
- Every minute's heartbeat carries queue sizes (`LUMBERJACK_QUEUES=default,redis:emails`; empty is the default queue) and host metrics (CPU load, memory, disk).

## Commands

`lumberjack:install`, `lumberjack:agent`, `lumberjack:terminate`, `lumberjack:test` (sends a test exception through the agent), `lumberjack:health` (`--check=<slug>`, `--no-fail`; exits 1 when a check failed, for deploy scripts).

## Testing an app that uses the package

There is no fake. Recording hands batches to the agent, which is not running in tests, so they are dropped; turn recording off to skip the work, and keep state reads from calling Lumberjack:

```xml
<!-- phpunit.xml -->
<env name="LUMBERJACK_ENABLED" value="false"/>
<env name="LUMBERJACK_HEALTH_STATES_PULL" value="false"/>
<env name="LUMBERJACK_HEALTH_COOLDOWN" value="0"/>
```

- Test a custom check by making it and asserting on its result: `expect(app(LicenseServerCheck::class)->run()->status)->toBe(CheckStatus::Ok)` (`ExeQue\Lumberjack\Health\CheckStatus`).
- Assert what is registered: `array_keys(app(ExeQue\Lumberjack\Health\HealthRegistry::class)->checks())` lists the slugs.
- Give code a check state with `app(ExeQue\Lumberjack\Health\HealthStates::class)->fill(['checks' => [['slug' => 'disk:s3', 'state' => 'down', 'message' => 'Bucket gone']]])`; `Lumberjack::health('disk:s3')->isHealthy()` is then false and `HealthStateChanged` is dispatched.
- Test a webhook handler by calling it through the handlers: `app(ExeQue\Lumberjack\Webhooks\Handlers::class)->handle(Alert::fromPayload(Status::New, $payload))`, or post a signed request (`X-Lumberjack-Timestamp`, `X-Lumberjack-Signature: sha256=` HMAC-SHA256 of `"{timestamp}.{body}"` with the secret).
