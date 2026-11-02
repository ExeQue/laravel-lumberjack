# Lumberjack for Laravel

**Documentation: [exeque.github.io/laravel-lumberjack](https://exeque.github.io/laravel-lumberjack/)**, or the [`docs`](docs/README.md) folder.

Sends a Laravel app's errors, requests, queries, jobs, artisan commands, scheduled tasks and log lines to [Lumberjack](https://github.com/ExeQue/lumberjack), through a local agent so a slow or unreachable Lumberjack never slows the app down. Browser errors come along with one Blade directive, and the app can react to Lumberjack's alerts through a signed webhook.

Requires PHP 8.3+ and Laravel 12 or 13.

```bash
composer require exeque/laravel-lumberjack
```

```dotenv
LUMBERJACK_URL=https://lumberjack.example.com
LUMBERJACK_TOKEN=lj_srv_...
LUMBERJACK_PUBLIC_KEY=pk_...
```

```bash
php artisan lumberjack:agent   # under Supervisor or systemd
php artisan lumberjack:test    # check it end to end
```

```blade
@lumberjack {{-- in the <head> of your layout, for browser errors --}}
```

```php
// React to alerts, in a service provider.
Lumberjack::onException(PaymentGatewayException::class, [Status::New, Status::Escalating], fn (Alert $alert) => StatusBanner::show('Payments are slow'));
```

## Documentation

- [Installation](docs/installation.md)
- [The agent](docs/agent.md)
- [What is recorded](docs/recording.md)
- [Logs](docs/logs.md)
- [Browser errors](docs/browser.md)
- [Reacting to alerts](docs/webhooks.md)
- [Artisan commands](docs/commands.md)
- [Configuration](docs/configuration.md)

## Development

See [Contributing](CONTRIBUTING.md).
