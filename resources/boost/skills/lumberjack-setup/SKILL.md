---
name: lumberjack-setup
description: "Use when asked to set up, install or tune Lumberjack (exeque/laravel-lumberjack) in a Laravel app, or to fit its config to how the app is built and deployed: reads composer.json, config and deploy files, runs lumberjack:install with the right options, and sets only the .env keys and config the app's stack needs (sampling, queues, health checks, browser errors, logs, release, the agent)."
---

# Lumberjack setup

Sets Lumberjack up to fit the app it is in. `php artisan lumberjack:install` does the wiring; this skill works out what to pass it and what to set besides, from what the app runs with. For the API itself (health checks, webhooks, activity, testing), use the `lumberjack-development` skill.

## Rules

- Ask the user for the three values from the project's settings page in Lumberjack (**Installation**): the URL, the server token and the public key. Never guess them, never print the token back, never write them anywhere but `.env`. `.env.example` gets the keys with empty values.
- Change the least: an `.env` key over a published config, a published config only when a setting has no env key. The package defaults are good; set a key only when the app's stack calls for it, and say why.
- Running it again must change nothing that is set up already. Read `.env` and `config/lumberjack.php` (when published) first and leave what is there unless the user asks.
- Do not add try/catch, retries or HTTP calls around Lumberjack: the package never throws into the app.
- What was broken before you started (a database host that does not resolve, a lock file that does not install on this PHP) is not yours to fix: work around it where you must, and report it.
- After editing PHP files, run the app's formatter (`vendor/bin/pint --dirty` when Pint is installed).

## 1. Look at the app

Read these, and keep notes on what you find:

| Look at | For |
|---|---|
| `composer show --direct` and `composer show <package>` (or `composer.lock`), not only `composer.json` | what is installed, including packages that come in through others (Livewire through `livewire/flux`, `livewire/volt` or Jetstream): `laravel/horizon`, `laravel/octane`, `livewire/livewire`, `inertiajs/inertia-laravel`, `laravel/ai`, `prism-php/prism`, `laravel/telescope`, `laravel/pulse`, a CSP package such as `spatie/laravel-csp` |
| `routes/console.php`, a cron or `schedule:work` anywhere (deploy files, `composer.json` scripts) | whether the scheduler runs: scheduled health checks need it |
| `config/queue.php`, `config/horizon.php` | the queue connections and the queues workers listen on |
| `config/cache.php`, `config/database.php` | the cache store, whether Redis is used |
| `config/filesystems.php` | remote disks (s3 and the like) |
| `config/logging.php` | the default channel and its `stack` |
| `resources/views/**` with `</head>` | the layouts (`app.blade.php` with Inertia) |
| `Dockerfile`, `compose.yaml`/`docker-compose.yml`, a `k8s/`/`helm/` folder, `Procfile`, `.github/workflows`, `Envoy.blade.php`, `deploy.php` | how the app is deployed and whether `.git` ships with it. A compose file built from `vendor/laravel/sail` is Sail, local development, not a deploy; workflows that only lint and test say nothing about the deploy either |
| the `dev` script in `composer.json` | where local long-running processes start (`queue:listen`, `pail`, `npm run dev`) |
| `.env`, `config/lumberjack.php` | what is set up already |

## 2. Run the installer

When the package is missing, install it: `composer require exeque/laravel-lumberjack` (PHP 8.3+, Laravel 12 or 13; say so and stop on an older Laravel). When Composer fails on a platform requirement the app's lock already does not meet on this machine (check with `composer check-platform-reqs`), that is not Lumberjack's: retry with `--ignore-platform-req=php` and tell the user. Ask before `-W` or updating other packages.

Build the command from what you found, and run it once:

```bash
php artisan lumberjack:install --no-interaction \
    --url=<url> --token=<server token> --key=<public key> \
    --checks=<checks> --layout=<layouts> --queues=<queues>
```

- `--checks`: comma separated, from what the app uses: `database` for the default connection (and `database:<connection>` for each other one the app writes to), `cache` when the store is not `array`/`file`, `redis` when cache or queue uses Redis, `disk:<disk>` per remote disk the app actually uses (the default disk, or one named in its code; not the `s3` every app's config defines with an empty bucket), `queue` or `queue:<connection>:<queue>` per queue workers listen on (not with Horizon), `horizon` with Horizon, `failed-jobs` with any queue other than `sync`, `migrations`, `debug-mode`, and `mail` when the mailer is SMTP. Leave `--checks` out to take the installer's own suggestion.
- `--layout`: the layouts with a `</head>`; `auto` when there is exactly one. With a nonce-based CSP, see step 3.
- `--queues`: `default,redis:emails` style, every queue a worker or Horizon supervisor listens on, so the agent reports their sizes. Leave it out when there is only the default queue.
- Leave out `--pull-token` unless the user uses Lumberjack's data sources; then ask for it too.
- When the user has no Lumberjack yet to check against (CI, a fresh machine), add `--no-verify`. It stops, writing nothing, when Lumberjack refuses the token.

It writes the `.env` keys, `app/Providers/LumberjackServiceProvider.php` with the health checks (registered in `bootstrap/providers.php`) and `@lumberjack` in the layouts, and prints how to run the agent.

## 3. Fit it to the stack

Set only the rows that apply. Each env key goes in `.env` (and empty in `.env.example` when the value differs per environment).

| When the app | Set | Why |
|---|---|---|
| serves a lot of traffic (ask: more than a few requests a second at peak) | `LUMBERJACK_SAMPLE_RATE=0.1` (or lower for very busy apps); jobs alike with `LUMBERJACK_JOBS_SAMPLE_RATE` | Every request is still counted exactly; sampling only thins out timings and queries. Slow requests (over `requests.always_slower_than_ms`, 1000 ms) and slow queries are kept anyway. Keep 1.0 on staging. |
| runs Horizon | nothing more: the installer adds the `horizon` check and the supervisors' queues | Horizon's own routes are ignored by default. |
| runs Octane | nothing | Each request gets its own trace through Octane's events. Run one agent per server, not per worker. |
| uses Livewire or Inertia | nothing | Livewire updates are recorded per component and method, Inertia visits, partial reloads and prefetches per kind. Only turn off with `LUMBERJACK_REQUESTS_LIVEWIRE=false` / `LUMBERJACK_REQUESTS_INERTIA=false` when asked. |
| uses Laravel AI or Prism | nothing | Token usage is recorded on its own. |
| runs Telescope or Pulse | publish the config and add `'telescope*'` / `'pulse*'` to `requests.ignore` when they are reachable in production | Their own polling is not the app's traffic. (`telescope*` is in the default list already; check before adding.) |
| uses Redis heavily and the user wants it on traces | `LUMBERJACK_SPANS_REDIS=true` | Off by default: there can be very many commands. |
| has a CSP with nonces | if the app calls `Vite::useCspNonce()`, nothing; otherwise replace `@lumberjack` with `@lumberjack($nonce)` using the app's nonce variable | The tag needs the same nonce as the rest of the page. |
| logs through a `stack` channel | add a channel `'lumberjack' => ['driver' => 'lumberjack', 'level' => 'warning']` to `config/logging.php` (ask which level the user wants). When the stack's channels come from `LOG_STACK` (Laravel's default `explode(',', env('LOG_STACK', 'single'))`), add `,lumberjack` to `LOG_STACK` in `.env` and `.env.example`; otherwise put `'lumberjack'` in its `channels` | Log lines on Lumberjack's Logs page and on each trace. |
| deploys without `.git` (Docker image, artifact, rsync) | `LUMBERJACK_RELEASE` from the build (a build arg or the CI's commit sha), or write `git rev-parse HEAD > latest-release` in the build and add `FileReleaseResolver` first in `release_resolver` | Without it the release is unknown, and issues cannot be tied to a deploy. With `.git` or a CI-installed vendor the default resolvers find it. |
| has no deploy you can find (and no `.git` here) | nothing; tell the user the release stays empty until production either has `.git`, a vendor installed in a git checkout, or `LUMBERJACK_RELEASE` | Do not guess a deploy. |
| has tests | `<env name="LUMBERJACK_ENABLED" value="false"/>` in `phpunit.xml` | A test run must not send to the local agent. Tests that assert on Lumberjack call `Lumberjack::fake()`, which works with it off. |
| uses a 404-heavy public site the user does not want counted | `requests.ignore_status => [404]` in the published config | Only when asked: 404s are traffic too. |

## 4. The agent and the scheduler

The app hands everything to a local agent (`php artisan lumberjack:agent`), which sends it to Lumberjack. Add it where the app's other long-running processes are defined, from what you found in step 1:

- **Forge, Supervisor**: a daemon/program `php artisan lumberjack:agent` in the app's directory, restarted always.
- **systemd**: a service next to the queue worker's, `Restart=always`.
- **Docker Compose**: a service from the app's own image, `command: php artisan lumberjack:agent --host=0.0.0.0`, no `ports:`, and `LUMBERJACK_AGENT_HOST=<that service's name>` on the app. Both need a shared cache store (Redis) for health states.
- **Kubernetes**: a sidecar container in the app's pod with `command: ["php", "artisan", "lumberjack:agent"]` and the app's environment.
- **Local development** (Sail, `composer run dev`): add `php artisan lumberjack:agent` to the `dev` script's processes next to `queue:listen`. Not to Sail's compose file.
- **Nothing found**: change nothing, and tell the user to run `php artisan lumberjack:agent` under their process manager in production.

Health checks run from the scheduler. When step 1 found no scheduler running, tell the user that production needs `* * * * * php artisan schedule:run` (or `schedule:work` under the process manager), and add `php artisan schedule:work` to the `dev` script when there is one.

In the deploy script, add `php artisan lumberjack:terminate` next to `queue:restart` / `horizon:terminate`, so the agent restarts on the new code. With JavaScript source maps in the build, add `php artisan lumberjack:sourcemaps` after the assets are built.

## 5. Check it

```bash
php artisan about               # a Lumberjack section with the URL and environment, and the release when one is found
php artisan lumberjack:health   # the health checks run once
php artisan lumberjack:test     # with the agent running: a test exception in Lumberjack within seconds
```

A check that fails here because of the app's own local setup (a database it cannot reach) is not Lumberjack's: report it, do not fix it. `debug-mode` passes locally and fails where `APP_DEBUG` is on in production, as meant. Without an agent running, `lumberjack:test` says so; that is expected.

Run the app's tests: nothing should change in them.

## 6. Tell the user

End with a short list: what the installer wrote, each key or config you set and why (one line each, tied to what you found in step 1), what you left at its default on purpose, what was broken before you started, and what is left for them (the agent and the scheduler on the server, the deploy script line, the keys in production's environment, the release).
