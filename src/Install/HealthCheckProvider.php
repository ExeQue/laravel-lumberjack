<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Install;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * The built-in checks lumberjack:install offers, suggested from the app's config, and the
 * service provider that registers the chosen ones. A choice is a key like "database",
 * "disk:s3" or "queue:redis:emails": the check, then its connection, disk or mailer.
 */
final class HealthCheckProvider
{
    /** Choice name => the registry method it becomes. */
    private const array METHODS = [
        'database' => 'database',
        'cache' => 'cache',
        'redis' => 'redis',
        'disk' => 'disk',
        'queue' => 'queue',
        'horizon' => 'horizon',
        'failed-jobs' => 'failedJobs',
        'debug-mode' => 'debugMode',
        'migrations' => 'migrations',
        'mail' => 'mail',
    ];

    public function __construct(private readonly Repository $config)
    {
    }

    /**
     * What to offer, choice => label, and which of them to pick by default.
     *
     * @return array{array<string, string>, list<string>}
     */
    public function options(): array
    {
        $options = $suggested = [];
        $offer = function (string $key, string $label, bool $suggest) use (&$options, &$suggested): void {
            $options[$key] = $label;

            if ($suggest) {
                $suggested[] = $key;
            }
        };

        $queue = $this->string('queue.default') ?? 'sync';
        $queueDriver = $this->string("queue.connections.{$queue}.driver") ?? $queue;
        $usesQueue = ! in_array($queueDriver, ['sync', 'null'], true);
        $cache = $this->string('cache.default') ?? 'file';
        $cacheDriver = $this->string("cache.stores.{$cache}.driver") ?? $cache;
        $mailer = $this->string('mail.default') ?? 'log';

        $offer('database', 'Database ('.($this->string('database.default') ?? 'default').' connection answers)', true);
        $offer('cache', "Cache ({$cache} store reads and writes)", ! in_array($cacheDriver, ['array', 'null'], true));

        if (is_array($this->config->get('database.redis.default'))) {
            $offer('redis', 'Redis (answers PING)', $cacheDriver === 'redis' || $queueDriver === 'redis');
        }

        foreach ((array) $this->config->get('filesystems.disks', []) as $disk => $settings) {
            if (! is_string($disk) || $disk === 'public') {
                continue;
            }

            // Every new app has an s3 disk; only one with a bucket is in use.
            $driver = is_array($settings) ? ($settings['driver'] ?? null) : null;
            $inUse = $driver !== 'local' && ($driver !== 's3' || ! empty($settings['bucket']));
            $offer("disk:{$disk}", "Disk {$disk} (write, read, delete".($driver === 'local' ? ', disk usage' : '').')', $inUse);
        }

        $offer('queue', "Queue size ({$queue} connection)", $usesQueue);

        if (class_exists('Laravel\Horizon\Horizon')) {
            $offer('horizon', 'Horizon is running', true);
        }

        $offer('failed-jobs', 'Failed jobs in the last hour', $usesQueue && $this->string('queue.failed.driver') !== 'null');
        $offer('debug-mode', 'Debug mode is off in production', true);
        $offer('migrations', 'No pending migrations', true);
        $offer('mail', "Mail ({$mailer} mailer logs in)", $this->string("mail.mailers.{$mailer}.transport") === 'smtp');

        return [$options, $suggested];
    }

    /**
     * The registry call for a choice, e.g. "->disk('s3')".
     */
    public static function call(string $choice): string
    {
        $parts = explode(':', trim($choice));
        $method = self::METHODS[array_shift($parts)] ?? throw new InvalidArgumentException("Unknown health check [{$choice}]. Choose from: ".implode(', ', array_keys(self::METHODS)).'.');
        $quoted = array_map(fn (string $part): string => var_export($part, true), $parts);

        $arguments = match (true) {
            $method === 'disk' && $quoted === [] => throw new InvalidArgumentException("The disk check needs a disk, e.g. [disk:s3]."),
            $method === 'queue' && count($quoted) > 1 => [$quoted[0], 'queue: '.$quoted[1]],
            in_array($method, ['horizon', 'failedJobs', 'debugMode'], true) => [],
            default => array_slice($quoted, 0, 1),
        };

        return "->{$method}(".implode(', ', $arguments).')';
    }

    /**
     * The service provider registering these choices.
     *
     * @param  list<string>  $choices
     */
    public static function render(string $namespace, array $choices): string
    {
        $calls = implode("\n", array_map(fn (string $choice): string => '            '.self::call($choice), $choices));

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use ExeQue\Lumberjack\Facades\Lumberjack;
            use Illuminate\Support\ServiceProvider;

            /**
             * Lumberjack's health checks, run every minute from the scheduler.
             * See https://exeque.github.io/laravel-lumberjack/docs/health-checks
             */
            class LumberjackServiceProvider extends ServiceProvider
            {
                public function boot(): void
                {
                    Lumberjack::healthChecks()
            {$calls};
                }
            }

            PHP;
    }

    private function string(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
