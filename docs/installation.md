# Installation

Lumberjack for Laravel needs PHP 8.3 or newer and Laravel 12 or 13.

## The package

```bash
composer require exeque/laravel-lumberjack
```

The service provider and the `Lumberjack` facade register themselves.

## Set it up

Create a project in Lumberjack; its settings page shows the URL, the server token and the public key under **Installation**. Then:

```bash
php artisan lumberjack:install
```

It asks for the three values and walks through the rest:

1. **Connection.** It sends a heartbeat with the token to check the URL and token, and says why when that fails (Lumberjack cannot be reached, the token is refused, another answer), so you can enter them again or carry on. The project's agents in Lumberjack list that heartbeat as an agent named `lumberjack:install`.
2. **`.env`.** It writes `LUMBERJACK_URL`, `LUMBERJACK_TOKEN` and `LUMBERJACK_PUBLIC_KEY`, and asks before replacing a value that is already there. The rest of the file stays as it was. It offers to add the keys to `.env.example` with empty values.
3. **Queues.** It suggests `LUMBERJACK_QUEUES` from `config/queue.php` and Horizon's supervisors, for the [queue sizes](agent.md#queue-sizes) the agent reports.
4. **Browser errors.** It lists the Blade views with a `</head>` (your layouts, or `app.blade.php` with Inertia) and puts `@lumberjack` before `</head>` in the ones you pick.
5. **Health checks.** It suggests [checks](health-checks.md) from your config (the database, the cache store, Redis when the cache or queue uses it, remote disks, the queue, Horizon when installed, failed jobs, debug mode, migrations, mail over SMTP; every disk but `public` can be picked) and writes the ones you pick to `app/Providers/LumberjackServiceProvider.php`, registered in `bootstrap/providers.php`. Your `AppServiceProvider` is left alone. When the provider exists, it asks before writing it again.
6. **The agent.** It asks how the app runs (Forge or Supervisor, systemd, Docker, Kubernetes) and prints the config to run [the agent](agent.md) with.
7. **Check.** When `.env` changed, it tells you to restart the agent and run `lumberjack:test`. When nothing changed and the agent answers, it offers to send the test exception itself.

Running it again changes nothing that is already set up, so it is safe to run to add what is missing.

### Without questions

For a script or CI, pass the values as options:

```bash
php artisan lumberjack:install --no-interaction \
    --url=https://lumberjack.example.com --token=lj_srv_... --key=pk_... \
    --checks=database,cache,disk:s3,queue:redis:emails,failed-jobs \
    --layout=resources/views/layouts/app.blade.php \
    --queues=default,redis:emails
```

Without `--checks` it uses the suggested checks, without `--layout` it leaves the views alone (`--layout=auto` picks the only layout there is), and without `--queues` it writes `LUMBERJACK_QUEUES` only when it found more than the default queue. A failed connection check stops it before anything is written; `--no-verify` skips the check. An existing value or provider is kept unless you pass `--force`. See [`lumberjack:install`](commands.md#lumberjack-install) for every option.

## By hand

Instead of the command, set the keys from the project's settings in `.env`:

```dotenv
LUMBERJACK_URL=https://lumberjack.example.com
LUMBERJACK_TOKEN=lj_srv_...
LUMBERJACK_PUBLIC_KEY=pk_...
```

- `LUMBERJACK_TOKEN` is the server token. Only [the agent](agent.md) uses it, to send to Lumberjack.
- `LUMBERJACK_PUBLIC_KEY` is for [browser errors](browser.md). Leave it out to send none.

Put `@lumberjack` in the `<head>` of your layout for [browser errors](browser.md), and register [health checks](health-checks.md) in a service provider.

## Environment and release

Set the environment and release too, so Lumberjack can tell them apart:

```dotenv
LUMBERJACK_ENVIRONMENT=production   # defaults to APP_ENV
LUMBERJACK_RELEASE=2026.11.1        # optional: found in .git or vendor when empty
```

Without `LUMBERJACK_RELEASE` the release is the commit in the app's `.git` directory, or else the commit Composer installed vendor from. See [Release](configuration.md#release) for other deploys.

## The agent

The app never talks to Lumberjack itself. It hands everything to a local agent, which sends it on, so a slow or unreachable Lumberjack cannot slow the app down. Run one per app per server, under Supervisor or systemd; see [The agent](agent.md).

```bash
php artisan lumberjack:agent
```

## Check it works

With the agent running:

```bash
php artisan lumberjack:test
```

A test exception shows up in Lumberjack within a few seconds. If the command says there is no agent, the agent is not running, or it listens on another port than the app sends to.

## Next

- [What is recorded](recording.md), and how to leave things out
- [Logs](logs.md), to send log lines to Lumberjack's log channels
- [Browser errors](browser.md), with one Blade directive
- [Reacting to alerts](webhooks.md), to turn on a status banner when something escalates
