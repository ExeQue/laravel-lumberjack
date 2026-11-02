# The agent

The agent is a small process that runs next to the app. The app hands it each batch over TCP on `127.0.0.1:2407`, with a 50 ms connect timeout: if the agent does not answer, the batch is dropped and the app carries on. The agent collects the batches and sends them to Lumberjack every 5 seconds, or as soon as it holds 1 MB, gzipped and signed with the server token.

```bash
php artisan lumberjack:agent
```

It reads the app's own `.env`, so the token is set in one place. Queue workers and artisan commands send to the same agent as web requests.

## Running it

Run one agent per app per server, and let the process manager restart it. With Supervisor:

```ini
[program:lumberjack-agent]
command=php /var/www/app/artisan lumberjack:agent
autostart=true
autorestart=true
stopwaitsecs=15
user=www-data
```

With systemd:

```ini
[Service]
ExecStart=/usr/bin/php /var/www/app/artisan lumberjack:agent
Restart=always
RestartSec=5
User=www-data
```

Several apps on one server each run their own agent on their own port (`LUMBERJACK_AGENT_PORT`). An agent sends everything with the token of the app it runs in, so it serves one project. Each app marks what it hands the agent with a short fingerprint of its token (never the token itself), and an agent turns away data from an app of another project instead of sending it under the wrong token. It warns once per such project in its output:

```
WARN  Turned away data from an app of another project (mark 3f9c0a1b2d4e). Give each app its own agent and LUMBERJACK_AGENT_PORT.
```

That warning means two apps point at the same port.

## Names

Lumberjack lists each agent under the project's settings, with the last time it was seen. By default an agent is named after its host. Give it a name of its own to tell several apart:

```bash
php artisan lumberjack:agent --name=queue
```

or `LUMBERJACK_AGENT_NAME=queue` in `.env`.

## When Lumberjack is down

A batch that cannot be sent is kept on disk in `storage/lumberjack/spool`, and tried again with a growing pause, up to 5 minutes. New batches wait behind it, so nothing arrives out of order. The spool holds at most 100 MB; past that, the oldest batches go first. A batch Lumberjack refuses as such (a 4xx other than 401, 408 or 429) is dropped rather than tried forever.

## Memory

Past 128 MB the agent sends what it has and exits, so the process manager starts a fresh one. Change it with `--memory=256` or `LUMBERJACK_AGENT_MEMORY`; `0` turns the limit off.

## After a deploy

Let the agent pick up the new code:

```bash
php artisan lumberjack:terminate
```

The agent sends what it holds and exits; Supervisor or systemd starts it again. Put it next to `queue:restart` or `horizon:terminate` in your deploy script.

## Heartbeat

Every minute the agent tells Lumberjack it is alive, with its host, name and version. A project's settings show each agent as online while its heartbeat is recent.
