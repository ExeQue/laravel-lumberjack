<!-- Part of the Lumberjack for Laravel documentation - see ../README.md -->

# Documentation

- [Installation](installation.md) - Install the package, set the keys and check it works
- [The agent](agent.md) - The process that sends everything on to Lumberjack
- [What is recorded](recording.md) - Exceptions, requests, queries, jobs, commands and scheduled tasks, and how to leave things out
- [Logs](logs.md) - The `lumberjack` log driver and log channels
- [Browser errors](browser.md) - The `@lumberjack` directive and the browser script
- [Health checks](health-checks.md) - Checks run from the scheduler: database, disks, queues, backups and your own
- [Data sources](data-sources.md) - Offer figures, series, lists and tables to status screens, connected once in Lumberjack, which then pulls them
- [Custom alerts](alerts.md) - Send your own alerts to the channels of a topic
- [Reacting to alerts](webhooks.md) - Webhooks, `Lumberjack::onException()` and signatures
- [Testing your app](testing.md) - Recording off in tests, and testing checks, states and webhook handlers
- [Artisan commands](commands.md) - `lumberjack:install`, `lumberjack:agent`, `lumberjack:health`, `lumberjack:terminate`, `lumberjack:sourcemaps` and `lumberjack:test`
- [Configuration](configuration.md) - Every option in `config/lumberjack.php`
- [Laravel Boost](boost.md) - Guidelines and a skill for AI assistants

Working on the package itself: [Contributing](../CONTRIBUTING.md).
