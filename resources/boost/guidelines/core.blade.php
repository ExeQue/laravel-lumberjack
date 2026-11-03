## Lumberjack (exeque/laravel-lumberjack)

Sends the app's exceptions, requests, queries, jobs, commands, scheduled tasks, spans and log lines to a Lumberjack server through a local agent process, runs health checks from the scheduler, and lets the app react to Lumberjack's alerts through a signed webhook.

### Install

- `php artisan lumberjack:install` sets it up: the `.env` keys, `@@lumberjack` in the layout, `app/Providers/LumberjackServiceProvider.php` with health checks, and how to run the agent. Pass `--no-interaction` with `--url`, `--token`, `--key`, `--checks=database,cache,disk:s3` and `--layout=resources/views/app.blade.php`.
- The `.env` keys: `LUMBERJACK_URL`, `LUMBERJACK_TOKEN` (server token, only the agent uses it), `LUMBERJACK_PUBLIC_KEY` (browser errors). `LUMBERJACK_ENABLED=false` turns recording off.
- The agent must run next to the app, one per app per server: `php artisan lumberjack:agent` under Supervisor, systemd or as a sidecar. `php artisan lumberjack:test` checks it end to end.
- Browser errors: the Blade directive in the `<head>` of the layout:

@verbatim
<code-snippet name="Browser script in the layout head" lang="blade">
<head>
    @lumberjack
</head>
</code-snippet>
@endverbatim

### Health checks

Register checks once, in a service provider's `boot()`, through `Lumberjack::healthChecks()`. Fire or read them anywhere with `Lumberjack::health('slug')`, which never registers anything.

@verbatim
<code-snippet name="Register health checks in boot()" lang="php">
use ExeQue\Lumberjack\Facades\Lumberjack;

public function boot(): void
{
    Lumberjack::healthChecks()
        ->database(maxLatencyMs: 200)
        ->disk('s3', slug: 'media-bucket')
        ->check('stripe', fn () => Http::get('https://status.stripe.com/api/v2/status.json')->ok())
        ->add(LicenseServerCheck::class);
}
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Fire a check and read its state" lang="php">
Lumberjack::health('media-bucket')->failed("Reading {$path} failed");

if (! Lumberjack::health('media-bucket')->isHealthy()) {
    // e.g. hide the upload button
}
</code-snippet>
@endverbatim

### Rules

- Recording never blocks or throws into the app: it hands batches to the local agent with a 50 ms timeout and drops them when the agent is not there. Do not wrap it in try/catch, add retries or send anything yourself.
- Never call Lumberjack's HTTP API from request code. Data goes through the agent; health states are read with `Lumberjack::health()` and `Lumberjack::healthStates()`, which are cached.
- Register health checks, webhook handlers (`Lumberjack::on()`, `Lumberjack::onException()`) and log channels in `boot()`, never in a request, job or `register()`.
- A health check slug is its identity in Lumberjack; changing one starts a new check.
- For anything beyond this (all built-in checks and their parameters, custom checks, release resolvers, webhooks, what is recorded, spans, log channels, the agent, testing), activate the `lumberjack-development` skill.
