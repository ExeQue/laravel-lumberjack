# Configuration

Publish the config to change it:

```bash
php artisan vendor:publish --tag=lumberjack-config
```

## Connection

| Key | Env | Default | |
|---|---|---|---|
| `enabled` | `LUMBERJACK_ENABLED` | `true` | Turns all recording off; the agent still runs |
| `url` | `LUMBERJACK_URL` | | The Lumberjack server |
| `token` | `LUMBERJACK_TOKEN` | | The project's server token; only the agent uses it |
| `public_key` | `LUMBERJACK_PUBLIC_KEY` | | For [browser errors](browser.md); without it `@lumberjack` renders nothing |
| `environment` | `LUMBERJACK_ENVIRONMENT` | `APP_ENV` | |
| `release` | `LUMBERJACK_RELEASE` | | E.g. the git sha |
| `announce` | `LUMBERJACK_ANNOUNCE` | `APP_DEBUG` | Whether the browser script says hello in the console |

## Agent

| Key | Env | Default | |
|---|---|---|---|
| `agent.host` | `LUMBERJACK_AGENT_HOST` | `127.0.0.1` | Where the agent listens and the app sends |
| `agent.port` | `LUMBERJACK_AGENT_PORT` | `2407` | |
| `agent.name` | `LUMBERJACK_AGENT_NAME` | the host | What Lumberjack lists the agent as |
| `agent.interval` | | `5` | Seconds between batches |
| `agent.spool_path` | | `storage/lumberjack/spool` | Where unsent batches wait |
| `agent.spool_max_bytes` | | 100 MB | Past this, the oldest batches go |
| `agent.memory_limit` | `LUMBERJACK_AGENT_MEMORY` | `128` | MB before the agent exits for a fresh one; 0 is no limit |

## Recording

See [What is recorded](recording.md) for what each section covers. Every `ignore` list takes `*` wildcards.

| Key | Env | Default |
|---|---|---|
| `requests.enabled` | `LUMBERJACK_REQUESTS` | `true` |
| `requests.sample_rate` | `LUMBERJACK_SAMPLE_RATE` | `1.0` |
| `requests.ignore` | | `up`, `horizon*`, `telescope*`, `_debugbar*` |
| `requests.ignore_methods` | | `OPTIONS`, `HEAD` |
| `requests.ignore_status` | | none |
| `queries.enabled` | `LUMBERJACK_QUERIES` | `true` |
| `queries.slower_than_ms` | `LUMBERJACK_QUERIES_SLOWER_THAN_MS` | `0` |
| `queries.ignore` | | none |
| `queries.ignore_connections` | | none |
| `queries.location` | `LUMBERJACK_QUERY_LOCATION` | `true` |
| `jobs.enabled` | `LUMBERJACK_JOBS` | `true` |
| `jobs.ignore` | | none |
| `jobs.ignore_queues` | | none |
| `jobs.parameters` | `LUMBERJACK_JOB_PARAMETERS` | `true` |
| `exceptions.enabled` | `LUMBERJACK_EXCEPTIONS` | `true` |
| `exceptions.ignore` | | none |
| `commands.enabled` | `LUMBERJACK_COMMANDS` | `true` |
| `commands.ignore` | | workers and other long-running commands |
| `schedule.enabled` | `LUMBERJACK_SCHEDULE` | `true` |
| `schedule.ignore` | | none |

## Webhook

See [Reacting to alerts](webhooks.md).

| Key | Env | Default | |
|---|---|---|---|
| `webhook.enabled` | `LUMBERJACK_WEBHOOK_ENABLED` | `false` | Whether the route exists |
| `webhook.secret` | `LUMBERJACK_WEBHOOK_SECRET` | | The channel's signing secret |
| `webhook.path` | `LUMBERJACK_WEBHOOK_PATH` | `lumberjack/webhook` | |

## Other

| Key | Default | |
|---|---|---|
| `base_path` | `base_path()` | Where the app's own code lives, for code lines and in-app frames |
| `scrub` | none | Extra context keys to blank before anything leaves the app |
