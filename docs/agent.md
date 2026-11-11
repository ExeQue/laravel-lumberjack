# The agent

The agent is a small process that runs next to the app. The app hands it each batch over TCP on `127.0.0.1:2407`, with a 50 ms connect timeout: if the agent does not answer, the batch is dropped and the app carries on. The agent collects the batches and sends them to Lumberjack every 5 seconds, or as soon as it holds 1 MB, gzipped, with the server token as a bearer token. A batch holds at most 1,000 events; more go in several.

```bash
php artisan lumberjack:agent
```

It reads the app's own `.env`, so the token is set in one place. Queue workers and artisan commands send to the same agent as web requests.

The agent itself is the package [`exeque/lumberjack-agent`](https://github.com/ExeQue/lumberjack-agent), which this package installs. `lumberjack:agent` runs it with what only Laravel knows on top: the queue sizes and workers, and the health states for the app's checks. The package also runs on its own, as `vendor/bin/lumberjack-agent`, for apps that are not Laravel; its README describes the line protocol for SDKs in other languages.

### Who may talk to it

Every line an app hands the agent carries the agent key, and the agent turns away any line
without it: data, and `lumberjack:terminate` too. The key is derived from
`LUMBERJACK_TOKEN` (an HMAC of it, never the token itself), so an app and an agent that
share the `.env` agree on it with nothing to set. An app that does not have the token sets
the same `LUMBERJACK_AGENT_KEY` as the agent. The app and the agent must run the same
package version: an agent from before the key turns nothing away, and an agent with the
key turns away an app from before it, with this warning once:

```
WARN  Turned away a line without the agent key. The app and the agent need the same LUMBERJACK_TOKEN (or LUMBERJACK_AGENT_KEY) and the same package version.
```

The agent also bounds what a client can make it hold: a line of at most 4 MB (the app
sends a larger batch in parts), 256 connections at once (more wait in the backlog), and
a connection that sends nothing for 10 seconds is closed.

The key keeps out what cannot read it, not a network you do not trust: it travels in
plain text. Keep the agent on `127.0.0.1` (the default), a pod's shared network, or a
private Docker network. Never bind it to `0.0.0.0` on a host whose port is reachable
from outside without a firewall.

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

With systemd, in `/etc/systemd/system/lumberjack-agent.service`, then `systemctl enable --now lumberjack-agent`:

```ini
[Unit]
Description=Lumberjack agent
After=network.target

[Service]
ExecStart=/usr/bin/php /var/www/app/artisan lumberjack:agent
Restart=always
RestartSec=5
User=www-data

[Install]
WantedBy=multi-user.target
```

`php artisan lumberjack:install` prints these for your app's path, and the ones below.

### Docker and Kubernetes

With Docker Compose, run the agent as its own service from the app's image. A container has its own `127.0.0.1`, so the agent listens on all of the container's addresses and the app sends to it by service name. Do not publish the agent's port (no `ports:` for it): only containers on the compose network should reach it.

```yaml
services:
    app:
        environment:
            LUMBERJACK_AGENT_HOST: lumberjack-agent
    lumberjack-agent:
        image: your-app-image # the app's own image
        command: php artisan lumberjack:agent --host=0.0.0.0
        restart: unless-stopped
```

On Kubernetes, run it as a sidecar container in the app's pod. Containers in a pod share its network, so the app reaches it on `127.0.0.1` as usual:

```yaml
containers:
    - name: lumberjack-agent
      image: your-app-image # the app's own image
      command: ["php", "artisan", "lumberjack:agent"]
      envFrom:
          - secretRef:
                name: your-app-env # the same environment as the app
```

A host name in `LUMBERJACK_AGENT_HOST` is looked up once a minute per process (with APCu, once a minute for the whole PHP-FPM pool), not on every batch. PHP-FPM without APCu looks it up once per request, a few milliseconds on Docker's DNS; set an IP to skip the lookup altogether.

Either way the agent writes [health states](health-checks.md#reading-states) into the app's cache, so use a store both containers reach, such as Redis.

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

A batch that cannot be sent is kept on disk in `storage/lumberjack/spool` (a directory only the agent's user can read, `0700`, with files `0600`: they hold the app's data), and tried again with a growing pause, up to 5 minutes. New batches wait behind it, so nothing arrives out of order. The spool holds at most 100 MB; past that, the oldest batches go first. When the spool cannot be written, the agent says so once in its output and in PHP's error log, and batches are dropped while Lumberjack is down. A batch Lumberjack refuses as such (a 4xx other than 401, 408 or 429) is dropped rather than tried forever; the agent says so once in its output.

The agent is one process with one thread: while it sends, it does not accept connections.
That time is bounded. One send may take `agent.send_timeout` seconds (5, or
`LUMBERJACK_AGENT_SEND_TIMEOUT`; connecting at most 2 s), and a flush or a pass over the
spool stops starting new sends after 250 ms, leaving the rest for the next tick. After an
outage the spool drains a few batches per tick rather than all at once. Meanwhile apps
still connect: the agent listens with a backlog of 1,024 connections, so a batch goes into
the kernel's buffer and the app carries on.

A redirect from `LUMBERJACK_URL` is not followed, so the token and the batches never go
anywhere else: the batch stays in the spool. Point `LUMBERJACK_URL` at the address
Lumberjack answers on, such as `https://` when a proxy upgrades http.

### What the app notices

Nothing. The app never talks to Lumberjack while it records (maintenance reports on
`artisan down` and `up` are the one exception, and fall back to the agent): it hands each batch to
the local agent over TCP with a 50 ms connect timeout and 1 s for the whole write. If the
agent is not there, for example while it restarts in a deploy, the batch waits in
`storage/lumberjack/app-spool` and is sent after the next batch that gets through, a few at
a time. That also goes for custom alerts. The spool holds at most 10 MB, a batch waits at most an hour, and files are
readable only by the user that wrote them, so run the app and its queue workers as one user.
Set `lumberjack.agent.app_spool_path` to `null` to drop batches instead. After a failed
send a process stops trying
for 5 seconds, so a queue worker does not pay the timeout on every job while the agent
is down; a batch that could not be kept is counted, and the first batch that gets through
again carries a warning log line saying how many were dropped. Lumberjack being slow or down only reaches
the agent, which spools. Recording itself is guarded too: a listener, the log
handler, the browser script or the work done when the app terminates gives up when it
fails, writes a line to PHP's error log and lets the app's request, job or command carry
on. Context is made plain data when it is recorded, so nothing of the app's (a model
accessor, a `jsonSerialize()`) runs later, inside the app's terminate. A mistake in
Lumberjack's own config (a health check, a release resolver) is skipped and reported
once, never thrown into the app's boot or scheduler.

The one call the app may make itself is reading health states back when the agent
has not kept them fresh ([health checks](health-checks.md#reading-states)): at most
once per ttl for everything that shares the cache, with a 0.5 s timeout, after the
response in a web request, and a failure gives `Unknown`, never an exception.

### In long-running processes

Queue workers, Octane and `schedule:work` keep the package in memory for hours. What it
holds is bounded: at most 1,000 events before they go to the agent, each job's or
command's state dropped when it ends, an unsampled run's queries held only until that
run ends, and the source files read for a stack trace forgotten before the next one. A
test runs thousands of jobs with queries, logs and failures and fails if memory grows.

## Memory

Past 128 MB the agent sends what it has and exits, so the process manager starts a fresh one. Change it with `--memory=256` or `LUMBERJACK_AGENT_MEMORY`; `0` turns the limit off.

## After a deploy

Let the agent pick up the new code:

```bash
php artisan lumberjack:terminate
```

The agent sends what it holds and exits; Supervisor or systemd starts it again. Put it next to `queue:restart` or `horizon:terminate` in your deploy script.

## Heartbeat

Every minute the agent tells Lumberjack it is alive, with its host, its name and what it is:

| Part      | Sent as                                                             |
| --------- | ------------------------------------------------------------------- |
| SDK       | `lumberjack.php.laravel` and the package version                    |
| Agent     | `lumberjack-agent-php` and the version of `exeque/lumberjack-agent` |
| Runtime   | `php` and the PHP version                                           |
| Framework | `laravel` and the Laravel version                                   |

A project's settings show each agent as online while its heartbeat is recent, with what it runs (e.g. "Laravel 0.1.0 · PHP 8.5.1"). The ids stay the same when packages are renamed; SDKs for other languages follow the same `lumberjack.<language>.<integration>` scheme.

## Queue sizes

The heartbeat also carries the size of the app's queues, so Lumberjack sees a backlog grow even when every worker is dead and no job starts. By default that is the default connection's default queue. Name others in `lumberjack.queues`, as `connection:queue` or a queue on the default connection:

```php
'queues' => ['default', 'redis:emails', 'sqs:imports'],
```

or `LUMBERJACK_QUEUES=default,redis:emails` in `.env`.

For each queue the agent sends:

| Field                 |                                                                                                                           |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `connection`, `queue` | Which queue                                                                                                               |
| `size`                | Jobs on it, as `Queue::size()` counts them (pending, delayed and reserved on Redis)                                       |
| `oldest_age_s`        | Seconds the oldest pending job has waited, where the driver can tell cheaply (Redis, database); otherwise `null`          |
| `workers`             | Workers running on it, from the [workers' signs of life](#queue-workers); left out when no worker has ever run on it here |

### Queue workers

Every queue worker (`queue:work`, `queue:listen` and Horizon's, which loop through the same worker) leaves a sign of life in the app's default cache, at most every 30 seconds per queue it takes. A worker that stops (Horizon scaling down, `queue:restart`) leaves at once; one that dies without stopping counts as gone after 90 seconds without a loop; one running a long job stays counted for the job's timeout plus 30 seconds. Writing it never fails a job.

The count is only as wide as the cache: with Redis or the database cache every host's workers count, with the file cache only those of the host the agent runs on.

A queue that cannot be measured (Redis is down, a connection that does not exist) is left out of that heartbeat; the heartbeat itself always goes out. The Jobs page shows the sizes, and a queue backlog alert can fire on them.

## Host metrics

The heartbeat also tells how busy the agent's host is, as `metrics`:

| Field                                     | Read from                                                                                                |
| ----------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| `cpu_load_1m`                             | `sys_getloadavg()`                                                                                       |
| `cpu_cores`                               | The container's CPU limit (cgroup v2 `cpu.max`), else the processors in `/proc/cpuinfo`                  |
| `memory_used_bytes`, `memory_total_bytes` | The container's memory (cgroup v2 `memory.current` and `memory.max`, or cgroup v1), else `/proc/meminfo` |
| `disk_used_bytes`, `disk_total_bytes`     | The disk the app lives on (`base_path()`)                                                                |
| `php_memory_peak_bytes`                   | The agent process's own peak memory                                                                      |

Inside a container the limits of the container count, not those of the machine; a container without a memory limit reports the machine's total. Nothing runs a program to find out, so it costs next to nothing. What the system does not tell (load and memory on Windows, memory and cores on macOS) is sent as `null`. Lumberjack shows the values and their trend on the project's agents.
