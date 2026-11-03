# Artisan commands

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
