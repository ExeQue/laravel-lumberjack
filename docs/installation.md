# Installation

Lumberjack for Laravel needs PHP 8.3 or newer and Laravel 12 or 13.

## The package

The package lives in a private GitHub repository. Add it as a VCS repository in your app's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:ExeQue/lumberjack-laravel.git" }
]
```

```bash
composer require exeque/lumberjack-laravel
```

The service provider and the `Lumberjack` facade register themselves.

## The keys

Create a project in Lumberjack. Its settings page, under **Install**, shows the values for `.env`:

```dotenv
LUMBERJACK_URL=https://lumberjack.example.com
LUMBERJACK_TOKEN=lj_srv_...
LUMBERJACK_PUBLIC_KEY=pk_...
```

- `LUMBERJACK_TOKEN` is the server token. Only [the agent](agent.md) uses it, to send to Lumberjack.
- `LUMBERJACK_PUBLIC_KEY` is for [browser errors](browser.md). Leave it out to send none.

Set the environment and release too, so Lumberjack can tell them apart:

```dotenv
LUMBERJACK_ENVIRONMENT=production   # defaults to APP_ENV
LUMBERJACK_RELEASE=2026.11.1        # e.g. the git sha of the deploy
```

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
