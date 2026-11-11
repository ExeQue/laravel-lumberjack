# Artisan commands

## `lumberjack:install`

Sets Lumberjack up in the app: checks the URL and token with Lumberjack, writes the keys to `.env` (and `.env.example`), puts `@lumberjack` in your layouts, writes `app/Providers/LumberjackServiceProvider.php` with health checks and registers it, suggests the queues to watch and prints how to run the agent. See [Installation](installation.md#set-it-up). Running it again changes nothing that is already set up.

| Option | |
|---|---|
| `--url=` | The Lumberjack server's URL; `LUMBERJACK_URL` when left out |
| `--token=` | The server token; `LUMBERJACK_TOKEN` when left out |
| `--key=` | The public key, for browser errors; `LUMBERJACK_PUBLIC_KEY` when left out |
| `--pull-token=` | The project's pull token, for [data sources](data-sources.md); `LUMBERJACK_PULL_TOKEN` |
| `--checks=` | Health checks, comma separated: `database`, `cache`, `redis`, `disk:<disk>`, `queue`, `horizon`, `failed-jobs`, `debug-mode`, `migrations`, `mail`. `database:<connection>`, `cache:<store>`, `redis:<connection>`, `queue:<connection>`, `queue:<connection>:<queue>`, `migrations:<connection>` and `mail:<mailer>` name one; `disk` always needs its disk. The suggested checks when left out. An unknown name stops the command before it writes anything |
| `--layout=` | A view to put `@lumberjack` in, relative to the app's root or absolute; repeat it or separate with commas for more. A file that does not exist is skipped with a warning. `auto` uses the only view under `resources/views` with a `</head>` and no `@lumberjack` yet, does nothing when there is none, and stops, writing nothing, when there are several |
| `--queues=` | `LUMBERJACK_QUEUES`, e.g. `default,redis:emails` |
| `--no-verify` | Do not check the URL and token with Lumberjack |
| `--force` | Replace values in `.env` and rewrite the provider without asking |

With `--no-interaction` it asks nothing: it uses the options and the suggested checks, leaves the views alone without `--layout`, keeps what exists without `--force`, and stops with exit code 1, writing nothing, when Lumberjack refuses the token or cannot be reached.

## `lumberjack:agent`

Runs [the agent](agent.md): it listens for the app's batches and sends them to Lumberjack. Run it under Supervisor or systemd.

| Option | |
|---|---|
| `--name=queue` | What Lumberjack lists the agent as; the host name by default (`LUMBERJACK_AGENT_NAME`) |
| `--host=127.0.0.1` | The address to listen on (`LUMBERJACK_AGENT_HOST`) |
| `--port=2407` | The port to listen on (`LUMBERJACK_AGENT_PORT`) |
| `--memory=128` | Exit past this many MB so a fresh agent starts; 0 is no limit (`LUMBERJACK_AGENT_MEMORY`) |

It needs `LUMBERJACK_URL` and `LUMBERJACK_TOKEN` and exits with 1 without them, or when it cannot listen on its port. With the `pcntl` extension it stops on `SIGTERM`, `SIGINT` or `SIGQUIT` after sending what it has; without it, use `lumberjack:terminate`. The agent records nothing of its own.

## `lumberjack:data-sources:test`

Checks the [data source](data-sources.md) setup the way Lumberjack will meet it: that the token is set, the URL is https, every source resolves, and that the endpoint answers a signed request over the network. It exits with 1 on a problem.

With no sources registered it warns and stops before the signed call.

| Option | |
|---|---|
| `--skip-http` | Only resolve the sources, without calling the endpoint |

## `lumberjack:terminate`

Asks the running agent to send what it has and exit, so the process manager starts it again with the new code. Use it after a deploy, like `horizon:terminate`. It talks to the agent over the same local port as the app. With no agent listening it says so and exits with 0, so a deploy script carries on.

## `lumberjack:health`

Runs the [health checks](health-checks.md), sends their results and prints them. It exits with 1 when a check failed, for deploy scripts. `--check=<slug>` runs one check (an unknown slug exits with 1), `--no-fail` always exits with 0. With no checks registered it says so and exits with 0. Its own runs are not recorded as commands. The scheduler runs it on its own once checks are registered.

## `lumberjack:sourcemaps`

Uploads the source maps of a build to Lumberjack, so [browser errors](browser.md) from minified bundles show the original code. Vite writes maps only with `build.sourcemap` set; `'hidden'` writes them without pointing the bundles at them:

```js
// vite.config.js
export default defineConfig({
    build: { sourcemap: 'hidden' },
});
```

Without it there are no maps, and the command warns and exits with 0. Run it in the deploy, after `vite build`. The machine that builds needs `LUMBERJACK_URL` and `LUMBERJACK_TOKEN`:

```bash
php artisan down
npm run build
php artisan lumberjack:sourcemaps --delete
php artisan up
```

| Option | |
|---|---|
| `--path=public/build/assets` | The directory with the `*.map` files, relative to the app's root or absolute; only its top level is read, not subdirectories. It must be inside the public directory: each map is sent with the URL path of its bundle, e.g. `/build/assets/app-3f9a.js` |
| `--delete` | Delete each map once it is uploaded, so the source is not served publicly |
| `--no-fail` | Exit with 0 when uploads failed, so a Lumberjack that is down does not stop the deploy. It still exits with 1 without `LUMBERJACK_URL` or `LUMBERJACK_TOKEN`, when the directory does not exist or is outside the public directory, and on `--delete` with `LUMBERJACK_ENABLED=false` |

Each map goes straight to Lumberjack with `LUMBERJACK_URL` and `LUMBERJACK_TOKEN`, not through the agent, so it works on a build machine without one. Lumberjack matches a frame to a map by its bundle's path, which Vite's hashed file names keep unique; the [release](configuration.md#release) a map is tagged with only breaks a tie between maps of one path (the error's release first, then the newest upload). It does decide how long a map is kept: past 90 days, Lumberjack keeps only the maps of the project's last 10 releases, so set `LUMBERJACK_RELEASE` when the build has no `.git`. It prints one line per map and exits with 1 when any upload failed (a map that failed is never deleted). An upload is tried 3 times while Lumberjack cannot be reached (5 seconds to connect), or while it answers 429 (it waits for `Retry-After`, at most 60 seconds), so builds with many chunks get through Lumberjack's rate limit of 120 uploads a minute per project; any other answer is final, and a redirect is reported rather than followed. Once Lumberjack cannot be reached or times out, the remaining maps are skipped instead of each waiting out its timeouts. A map over 20 MB, the most Lumberjack takes, is skipped and counts as failed; a large one gets longer to upload than the 30 seconds a small one does. Behind nginx, a map over 1 MB is refused with 413 unless `client_max_body_size` is raised; see [Reverse proxy](https://exeque.github.io/lumberjack/docs/docker#reverse-proxy) in the server's docs. With `LUMBERJACK_ENABLED=false` nothing is uploaded; with `--delete` that exits with 1, as the maps are still there. A 2xx that is not Lumberjack's answer (another app at `LUMBERJACK_URL`) counts as failed, so `--delete` keeps the maps, and so does a map that could not be deleted. The directory may be a symlink (a build shared between releases); a map symlinked out of it is refused. No maps in the directory is a warning, not a failure.

The project's **Settings** > **Source maps** in Lumberjack lists the uploaded maps. Errors received before their maps were uploaded keep their minified frames.

## `lumberjack:test`

Sends a test exception through the agent, to check an install end to end. It fails with a hint when no agent answers, and when `LUMBERJACK_ENABLED` is `false`.
