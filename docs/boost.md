# Laravel Boost

The package ships guidelines and a skill for [Laravel Boost](https://github.com/laravel/boost), so an AI assistant working on your app knows how to use Lumberjack.

- **A guideline**, always in the assistant's context: what the package does, how to install it, how to register and fire health checks, and the rules it must keep (the package never blocks the app, the app never calls Lumberjack's API from request code, checks, webhook handlers and log channels are registered in `boot()`, and a check's slug is its identity).
- **The `lumberjack-development` skill**, loaded when the assistant works on Lumberjack: every built-in health check with its parameters, custom and closure checks, thresholds, reading states, release resolvers, webhook handlers, what is recorded, sampling, spans, log channels, the browser script, the agent and how to test an app that uses the package.

## Installing them

Boost picks them up from the installed package. With Boost in the app:

```bash
php artisan boost:install   # the first time: choose Lumberjack among the packages
php artisan boost:update    # after updating the package, to refresh them
```

They are written to the assistant's files (`CLAUDE.md`, `AGENTS.md`, `.claude/skills`, ... depending on the assistant), like the guidelines of Laravel's own packages.
