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

## 1. Look at the app

Read these, and keep notes on what you find:

| Look at | For |
|---|---|
| `composer.json` require | `exeque/laravel-lumberjack` (install it with `composer require exeque/laravel-lumberjack` when missing), `laravel/horizon`, `laravel/octane`, `livewire/livewire`, `inertiajs/inertia-laravel`, `laravel/ai`, `prism-php/prism`, `laravel/telescope`, `laravel/pulse`, a CSP package such as `spatie/laravel-csp` |
| `config/queue.php`, `config/horizon.php` | the queue connections and the queues workers listen on |
| `config/cache.php`, `config/database.php` | the cache store, whether Redis is used |
| `config/filesystems.php` | remote disks (s3 and the like) |
| `config/logging.php` | the default channel and its `stack` |
| `resources/views/**` with `</head>` | the layouts (`app.blade.php` with Inertia) |
| `Dockerfile`, `compose.yaml`/`docker-compose.yml`, a `k8s/`/`helm/` folder, `Procfile`, `.github/workflows`, `Envoy.blade.php`, `deploy.php` | how the app is deployed and whether `.git` ships with it |
| `.env`, `config/lumberjack.php` | what is set up already |

## 2. Run the installer

Build the command from what you found, and run it once:

```bash
php artisan lumberjack:install --no-interaction \
    --url=<url> --token=<server token> --key=<public key> \
    --checks=<checks> --layout=<layouts> --queues=<queues>
```

- `--checks`: comma separated, from what the app uses: `database` (one per connection the app writes to, `database:<connection>`), `cache` when the store is not `array`/`file`, `redis` when cache or queue uses Redis, `disk:<disk>` per remote disk, `queue` or `queue:<connection>:<queue>` per queue workers listen on (not with Horizon), `horizon` with Horizon, `failed-jobs` with any queue other than `sync`, `migrations`, `debug-mode`, and `mail` when the mailer is SMTP. Leave `--checks` out to take the installer's own suggestion.
- `--layout`: the layouts with a `</head>`; `auto` when there is exactly one. With a nonce-based CSP, see step 3.
- `--queues`: `default,redis:emails` style, every queue a worker or Horizon supervisor listens on, so the agent reports their sizes. Leave it out when there is only the default queue.
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
| logs through a `stack` channel | add a channel `'lumberjack' => ['driver' => 'lumberjack', 'level' => 'warning']` to `config/logging.php` and put `'lumberjack'` in the stack's `channels`, after asking which level the user wants | Log lines on Lumberjack's Logs page and on each trace. |
| deploys without `.git` (Docker image, artifact, rsync) | `LUMBERJACK_RELEASE` from the build (a build arg or the CI's commit sha), or write `git rev-parse HEAD > latest-release` in the build and add `FileReleaseResolver` first in `release_resolver` | Without it the release is unknown, and issues cannot be tied to a deploy. With `.git` or a CI-installed vendor the default resolvers find it. |
| uses a 404-heavy public site the user does not want counted | `requests.ignore_status => [404]` in the published config | Only when asked: 404s are traffic too. |

## 4. The agent

The app hands everything to a local agent (`php artisan lumberjack:agent`), which sends it to Lumberjack. Add it where the app's other long-running processes are defined, from what you found in step 1:

- **Forge, Supervisor**: a daemon/program `php artisan lumberjack:agent` in the app's directory, restarted always.
- **systemd**: a service next to the queue worker's, `Restart=always`.
- **Docker Compose**: a service from the app's own image, `command: php artisan lumberjack:agent --host=0.0.0.0`, no `ports:`, and `LUMBERJACK_AGENT_HOST=<that service's name>` on the app. Both need a shared cache store (Redis) for health states.
- **Kubernetes**: a sidecar container in the app's pod with `command: ["php", "artisan", "lumberjack:agent"]` and the app's environment.

In the deploy script, add `php artisan lumberjack:terminate` next to `queue:restart` / `horizon:terminate`, so the agent restarts on the new code. With JavaScript source maps in the build, add `php artisan lumberjack:sourcemaps` after the assets are built.

## 5. Check it

```bash
php artisan about           # a Lumberjack section with the URL, environment and release
php artisan lumberjack:health   # the health checks run once
php artisan lumberjack:test     # with the agent running: a test exception in Lumberjack within seconds
```

Run the app's tests; with Lumberjack in them, `Lumberjack::fake()` keeps anything from leaving.

## 6. Tell the user

End with a short list: what the installer wrote, each key or config you set and why (one line each, tied to what you found in step 1), what you left at its default on purpose, and what is left for them (adding the agent to the server, the deploy script line, setting the keys in production's environment).
