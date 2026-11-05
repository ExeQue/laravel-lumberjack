# Contributing

Keep a change small and covered by tests. CI checks the formatting, runs static analysis and runs the suite on Laravel 12 and 13.

## The workbench

The workbench is a small demo app that sends to a local Lumberjack: buttons for an exception, an N+1, a slow request, log lines, a job and a failing job, health checks to break and fix (one of them on a RustFS bucket), plus browser errors through `@lumberjack`.

1. Seed your local Lumberjack (`php artisan migrate:fresh --seed`), or create a project there and add `https://laravel-lumberjack.test` to its allowed origins.
2. Copy `.env.example` to `.env`. It holds the keys of the Demo project that Lumberjack's seeder creates; for another project, use the keys from its settings page.

### With lerd

[lerd](https://github.com/lerd-env/lerd) serves the workbench at `https://laravel-lumberjack.test`, and `.lerd.yaml` defines three workers: the agent, a queue worker and the scheduler.

```bash
composer install
lerd worker start agent
lerd worker start queue
```

Under WSL, run `lerd wsl-hosts:sync` once so the domain resolves.

`.lerd.yaml` also asks for lerd's RustFS service (S3 compatible object storage), which lerd starts with the site. The workbench's `rustfs` disk points at a bucket on it, with lerd's default local credentials in `.env.example` (`RUSTFS_*`; not `AWS_*`, which Testbench's skeleton already sets empty). Create the bucket once; running it again does nothing:

```bash
php vendor/bin/testbench demo:bucket
```

The `disk:rustfs` health check writes, reads back and deletes a file in that bucket every 5 minutes (`->disk('rustfs')->every(5)`, the demo of a check with its own interval): the scheduler runs `lumberjack:health` every minute and skips it in between, and Lumberjack shows it as **every 5 min** without calling it stale. `php vendor/bin/testbench lumberjack:health --all` runs it at once. Since a failure only counts every 5 minutes, it takes about 10 minutes to degrade. To see it fail, press "Break disk:rustfs" on the demo page (the disk then points at a bucket that does not exist), or stop RustFS with `lerd service stop rustfs`. `php` through lerd's shim starts the site's services again, so while it is stopped leave the runs to the scheduler worker.

The workbench app runs on SQLite, which has no connections to report, so `database:mysql`, `database:mariadb` and `database:pgsql` checks run against lerd's MySQL, MariaDB and PostgreSQL services (listed under `services` in `.lerd.yaml`, lerd's default local login, their system databases; see `WORKBENCH_*` in `.env.example`). Each reports connections, the server's limit and the size, and warns at 80 % of the limit.

The `queue:database:bulk` health check watches the size of the bulk queue, which no worker runs: a warning at 100 jobs, failed at 1000. "Flood the bulk queue" on the demo page puts 1200 jobs on it, so the check degrades after 2 runs and goes down after 5; "Drain the bulk queue" empties it and the check recovers after 5 good runs.

### Without lerd

```bash
composer install
composer agent                                   # the agent, in its own terminal
php vendor/bin/testbench queue:work --tries=1    # the queue, in its own terminal
composer serve
```

Open `http://localhost:8000`.

Without lerd's RustFS, run any S3 compatible store (e.g. `docker run -p 9000:9000 rustfs/rustfs`) and set `RUSTFS_ENDPOINT` and the keys in `.env`; otherwise `disk:rustfs` just fails.

## Checks

```bash
composer test       # the suite
composer analyse    # PHPStan, level 7
composer lint       # format the code
composer lint:test  # check the formatting, as CI does
```

## Documentation

The documentation is the markdown in `docs/` and this repository's README; VitePress builds the site from it, and fails on a dead link.

```bash
npm install
npm run docs:dev     # http://localhost:5173/laravel-lumberjack/
npm run docs:build
```

## Pull requests

- For anything bigger than a bug fix, open an issue first so we can agree on the approach.
- Security problems go through [SECURITY.md](SECURITY.md), never a public issue.
- Use [Conventional Commits](https://www.conventionalcommits.org/) for commit messages and the pull request title, e.g. `fix(agent): retry when the server is unreachable`.
- A new or changed feature updates its page in `docs/` in the same pull request.
- By contributing you agree that your work is released under the [MIT License](LICENSE).
