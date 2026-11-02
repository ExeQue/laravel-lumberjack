# Contributing

Keep a change small and covered by tests. CI checks the formatting, runs static analysis and runs the suite on Laravel 12 and 13.

## The workbench

The workbench is a small demo app that sends to a local Lumberjack: buttons for an exception, an N+1, a slow request, log lines, a job and a failing job, plus browser errors through `@lumberjack`.

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

### Without lerd

```bash
composer install
composer agent                                   # the agent, in its own terminal
php vendor/bin/testbench queue:work --tries=1    # the queue, in its own terminal
composer serve
```

Open `http://localhost:8000`.

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
