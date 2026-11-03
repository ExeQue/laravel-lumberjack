# Artisan commands

## `lumberjack:install`

Sets Lumberjack up in the app: checks the URL and token with Lumberjack, writes the keys to `.env` (and `.env.example`), puts `@lumberjack` in your layouts, writes `app/Providers/LumberjackServiceProvider.php` with health checks and registers it, suggests the queues to watch and prints how to run the agent. See [Installation](installation.md#set-it-up). Running it again changes nothing that is already set up.

| Option | |
|---|---|
| `--url=` | The Lumberjack server's URL; `LUMBERJACK_URL` when left out |
| `--token=` | The server token; `LUMBERJACK_TOKEN` when left out |
| `--key=` | The public key, for browser errors; `LUMBERJACK_PUBLIC_KEY` when left out |
| `--checks=` | Health checks, comma separated: `database`, `cache`, `redis`, `disk:<disk>`, `queue`, `queue:<connection>:<queue>`, `horizon`, `failed-jobs`, `debug-mode`, `migrations`, `mail`; `database:<connection>`, `cache:<store>`, `redis:<connection>` and `mail:<mailer>` name one. The suggested checks when left out |
| `--layout=` | A view to put `@lumberjack` in, relative to the app's root; repeat it or separate with commas for more. `auto` uses the only layout with a `</head>` and stops, writing nothing, when there are several |
| `--queues=` | `LUMBERJACK_QUEUES`, e.g. `default,redis:emails` |
| `--no-verify` | Do not check the URL and token with Lumberjack |
| `--force` | Replace values in `.env` and the provider without asking |

With `--no-interaction` it asks nothing: it uses the options and the suggested checks, leaves the views alone without `--layout`, keeps what exists without `--force`, and stops with exit code 1, writing nothing, when Lumberjack refuses the token or cannot be reached.

## `lumberjack:agent`

Runs [the agent](agent.md): it listens for the app's batches and sends them to Lumberjack. Run it under Supervisor or systemd.

| Option | |
|---|---|
| `--name=queue` | What Lumberjack lists the agent as; the host name by default (`LUMBERJACK_AGENT_NAME`) |
| `--host=127.0.0.1` | The address to listen on (`LUMBERJACK_AGENT_HOST`) |
| `--port=2407` | The port to listen on (`LUMBERJACK_AGENT_PORT`) |
| `--memory=128` | Exit past this many MB so a fresh agent starts; 0 is no limit (`LUMBERJACK_AGENT_MEMORY`) |

It needs `LUMBERJACK_URL` and `LUMBERJACK_TOKEN`, and stops on `SIGTERM`, `SIGINT` or `SIGQUIT` after sending what it has.

## `lumberjack:terminate`

Asks the running agent to send what it has and exit, so the process manager starts it again with the new code. Use it after a deploy, like `horizon:terminate`. It talks to the agent over the same local port as the app.

## `lumberjack:health`

Runs the [health checks](health-checks.md), sends their results and prints them. It exits with 1 when a check failed, for deploy scripts. `--check=<slug>` runs one check, `--no-fail` always exits with 0. The scheduler runs it on its own once checks are registered.

## `lumberjack:test`

Sends a test exception through the agent, to check an install end to end. It fails with a hint when no agent answers.
