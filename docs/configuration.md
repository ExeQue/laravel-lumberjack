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
| `release` | `LUMBERJACK_RELEASE` | | Wins over the resolver; see [Release](#release) |
| `release_resolver` | | `[Git, Composer]` | Works out the release when `LUMBERJACK_RELEASE` is empty; see [Release](#release) |
| `announce` | `LUMBERJACK_ANNOUNCE` | `APP_DEBUG` | Whether the browser script says hello in the console |

## Release

Lumberjack tags every exception, request and job with the release, so it can tell when
an issue started or came back. `LUMBERJACK_RELEASE` sets it. When it is empty, the
`release_resolver` works it out once per process.

The default tries two resolvers and uses the first that finds a release:

```php
use ExeQue\Lumberjack\Release\ComposerReleaseResolver;
use ExeQue\Lumberjack\Release\GitReleaseResolver;

'release_resolver' => [
    GitReleaseResolver::class,
    ComposerReleaseResolver::class,
],
```

| Resolver | Release |
|---|---|
| `GitReleaseResolver` | The commit checked out in the app's `.git` directory, read without running git. Follows branches (loose or packed refs), a detached HEAD and worktrees. |
| `ComposerReleaseResolver` | The commit `composer install` ran in, from `vendor/composer/installed.php`. Survives a deploy that copies the app without `.git`, as long as vendor was installed in a git checkout (e.g. on CI). |
| `FileReleaseResolver` | The first line of a file the deploy writes. See below. |
| `StackReleaseResolver` | The first release found by its `resolvers`, tried in order. A plain list in `release_resolver` is one; you rarely name it yourself. |

All resolvers live in `ExeQue\Lumberjack\Release`.

A deploy that copies files without `.git` (rsync, an artifact, a Docker image) can
write the release to a file at build time and read it with `FileReleaseResolver`. The
path is relative to the app's root:

```bash
git rev-parse HEAD > latest-release   # before rsync
```

```php
// config/lumberjack.php: the file first, then the defaults
use ExeQue\Lumberjack\Release\FileReleaseResolver;

'release_resolver' => [
    [FileReleaseResolver::class, 'file' => 'latest-release'],
    GitReleaseResolver::class,
    ComposerReleaseResolver::class,
],
```

It reads the first line of the file, and `REVISION` when no `file` is given. An
absolute path (starting with `/`) works too. A missing or empty file means no release.

`release_resolver` takes a list of entries, tried in order, or a single entry. An
entry is a class, or `[Class::class, 'parameter' => value]`.

Your own class works too: implement `ExeQue\Lumberjack\Contracts\ReleaseResolver`.
The container makes it with those constructor parameters, so other dependencies are
injected, and passes the app's root as `path` if the constructor takes one:

```php
use ExeQue\Lumberjack\Contracts\ReleaseResolver;
use Illuminate\Contracts\Cache\Repository;

final class DeployedRelease implements ReleaseResolver
{
    public function __construct(private readonly Repository $cache, private readonly string $key) {}

    public function resolve(): ?string
    {
        $release = $this->cache->get($this->key);

        return is_string($release) ? $release : null;
    }
}
```

```php
'release_resolver' => [App\Support\DeployedRelease::class, 'key' => 'deploy:release'],
```

Set it to `null` to send no release.

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
| `queues` | `LUMBERJACK_QUEUES` | the default queue | Queues whose size the heartbeat reports, comma separated in the env; see [Queue sizes](agent.md#queue-sizes) |

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
