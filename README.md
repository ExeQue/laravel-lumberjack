<p align="center">
<a href="https://packagist.org/packages/exeque/laravel-lumberjack"><img src="https://img.shields.io/packagist/v/exeque/laravel-lumberjack" alt="Latest version"></a>
<a href="https://packagist.org/packages/exeque/laravel-lumberjack"><img src="https://img.shields.io/packagist/dt/exeque/laravel-lumberjack" alt="Total downloads"></a>
<a href="https://github.com/ExeQue/laravel-lumberjack/actions/workflows/tests.yml"><img src="https://github.com/ExeQue/laravel-lumberjack/actions/workflows/tests.yml/badge.svg?branch=main" alt="Tests"></a>
<a href="https://github.com/ExeQue/laravel-lumberjack/actions/workflows/docs.yml"><img src="https://github.com/ExeQue/laravel-lumberjack/actions/workflows/docs.yml/badge.svg" alt="Documentation"></a>
<img src="https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fraw.githubusercontent.com%2FExeQue%2Flaravel-lumberjack%2Fmain%2Fcomposer.json&query=%24.require.php&label=php&color=777bb4" alt="PHP version">
<img src="https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fraw.githubusercontent.com%2FExeQue%2Flaravel-lumberjack%2Fmain%2Fcomposer.json&query=%24.require%5B%22illuminate%2Fsupport%22%5D&label=laravel&color=ff2d20" alt="Laravel version">
<a href="https://github.com/ExeQue/laravel-lumberjack/blob/main/LICENSE"><img src="https://img.shields.io/github/license/ExeQue/laravel-lumberjack" alt="License"></a>
</p>

# Lumberjack for Laravel

**Documentation: [exeque.github.io/laravel-lumberjack](https://exeque.github.io/laravel-lumberjack/)**, or the [`docs`](https://exeque.github.io/laravel-lumberjack/docs/) folder.

Sends a Laravel app's errors, requests, queries, jobs, artisan commands, scheduled tasks and log lines to [Lumberjack](https://exeque.github.io/lumberjack/), through a local [agent](https://github.com/ExeQue/lumberjack-agent) so a slow or unreachable Lumberjack never slows the app down. Browser errors come along with one Blade directive, and the app can react to Lumberjack's alerts through a signed webhook.

Requires PHP 8.3+ and Laravel 12 or 13.

```bash
composer require exeque/laravel-lumberjack
php artisan lumberjack:install   # keys, browser script, health checks, how to run the agent
php artisan lumberjack:agent     # under Supervisor or systemd
php artisan lumberjack:test      # check it end to end
```

```php
// React to alerts, in a service provider.
Lumberjack::onException(PaymentGatewayException::class, [Status::New, Status::Escalating], fn (Alert $alert) => StatusBanner::show('Payments are slow'));
```

## Documentation

- [Installation](https://exeque.github.io/laravel-lumberjack/docs/installation)
- [The agent](https://exeque.github.io/laravel-lumberjack/docs/agent)
- [What is recorded](https://exeque.github.io/laravel-lumberjack/docs/recording)
- [Logs](https://exeque.github.io/laravel-lumberjack/docs/logs)
- [Browser errors](https://exeque.github.io/laravel-lumberjack/docs/browser)
- [Health checks](https://exeque.github.io/laravel-lumberjack/docs/health-checks)
- [Reacting to alerts](https://exeque.github.io/laravel-lumberjack/docs/webhooks)
- [Testing your app](https://exeque.github.io/laravel-lumberjack/docs/testing)
- [Artisan commands](https://exeque.github.io/laravel-lumberjack/docs/commands)
- [Configuration](https://exeque.github.io/laravel-lumberjack/docs/configuration)
- [Laravel Boost](https://exeque.github.io/laravel-lumberjack/docs/boost)

## Development

See [Contributing](https://exeque.github.io/laravel-lumberjack/CONTRIBUTING).
