<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Dotenv\Dotenv;
use ExeQue\Lumberjack\Events\HealthStateChanged;
use ExeQue\Lumberjack\Facades\Lumberjack;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Listeners\LogHealthStateChange;

/**
 * Points the demo at a local Lumberjack. The keys come from the package's own
 * .env (see .env.example), which the web and the CLI both read here, since
 * Testbench runs from its skeleton under vendor/.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Dotenv::createImmutable(dirname(__DIR__, 3))->safeLoad();

        // Testbench runs the app from vendor/; the demo's own code is here.
        config()->set('lumberjack.base_path', dirname(__DIR__, 3));
        config()->set('lumberjack.url', rtrim((string) env('LUMBERJACK_URL', 'https://lumberjack.test'), '/'));
        config()->set('lumberjack.token', env('LUMBERJACK_TOKEN'));
        config()->set('lumberjack.public_key', env('LUMBERJACK_PUBLIC_KEY'));
        config()->set('lumberjack.environment', 'workbench');
        // The demo's Deploy button writes a release here, as a deploy script would.
        $release = self::path('storage/release');
        config()->set('lumberjack.release', is_file($release) ? trim((string) file_get_contents($release)) : env('LUMBERJACK_RELEASE', 'dev'));
        // The agent reports the size of both queues; nothing works the bulk one.
        config()->set('lumberjack.queues', ['database:default', 'database:bulk']);
        config()->set('lumberjack.agent.port', (int) env('LUMBERJACK_AGENT_PORT', 2408));
        config()->set('lumberjack.agent.spool_path', self::path('storage/spool'));

        config()->set('logging.channels.lumberjack', ['driver' => 'lumberjack']);
        config()->set('logging.channels.payments', ['driver' => 'lumberjack', 'channel' => 'payments']);
        config()->set('logging.channels.stack', ['driver' => 'stack', 'channels' => ['single', 'lumberjack']]);
        config()->set('logging.default', 'stack');

        // A real queue, so jobs run in a worker with their own trace.
        config()->set('database.connections.workbench', ['driver' => 'sqlite', 'database' => $this->database(), 'foreign_key_constraints' => true]);
        config()->set('database.default', 'workbench');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database', ['driver' => 'database', 'connection' => 'workbench', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        config()->set('queue.failed.driver', 'null');

        // lerd's MySQL, MariaDB and PostgreSQL, only for the database health checks: the
        // app runs on SQLite, which has no connections to report.
        foreach (['mysql' => 'lerd-mysql', 'mariadb' => 'lerd-mariadb-11-8'] as $driver => $host) {
            $key = strtoupper($driver);
            config()->set("database.connections.{$driver}", [
                'driver' => $driver,
                'host' => env("WORKBENCH_{$key}_HOST", $host),
                'port' => (int) env("WORKBENCH_{$key}_PORT", 3306),
                'database' => env("WORKBENCH_{$key}_DATABASE", 'mysql'),
                'username' => env("WORKBENCH_{$key}_USERNAME", 'root'),
                'password' => env("WORKBENCH_{$key}_PASSWORD", 'lerd'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ]);
        }

        config()->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('WORKBENCH_PGSQL_HOST', 'lerd-postgres'),
            'port' => (int) env('WORKBENCH_PGSQL_PORT', 5432),
            'database' => env('WORKBENCH_PGSQL_DATABASE', 'postgres'),
            'username' => env('WORKBENCH_PGSQL_USERNAME', 'postgres'),
            'password' => env('WORKBENCH_PGSQL_PASSWORD', 'lerd'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);

        // A bucket on lerd's RustFS service, for the disk:rustfs health check. RUSTFS_*,
        // not AWS_*: Testbench's skeleton .env already sets those empty. The demo's
        // Break button points the disk at a bucket that does not exist.
        config()->set('filesystems.disks.rustfs', [
            'driver' => 's3',
            'key' => env('RUSTFS_KEY', 'lerd'),
            'secret' => env('RUSTFS_SECRET', 'lerdpassword'),
            'region' => env('RUSTFS_REGION', 'us-east-1'),
            'bucket' => is_file(self::path('storage/rustfs-down')) ? 'lumberjack-workbench-missing' : env('RUSTFS_BUCKET', 'lumberjack-workbench'),
            'endpoint' => env('RUSTFS_ENDPOINT', 'http://lerd-rustfs:9000'),
            'use_path_style_endpoint' => true,
        ]);
    }

    public function boot(): void
    {
        // Scheduled tasks for the Schedule page: one that works, one that fails, one closure.
        Artisan::command('demo:report', function (): int {
            DB::select('select 1 as report');
            Log::info('Daily report built');

            return 0;
        })->purpose('Build the demo report');

        Artisan::command('demo:broken', fn (): int => 1)->purpose('A task that always fails');

        Artisan::command('demo:bucket', function (): int {
            /** @var \Illuminate\Filesystem\AwsS3V3Adapter $disk */
            $disk = Storage::disk('rustfs');
            // The real bucket, also while the demo has the disk broken.
            $bucket = (string) env('RUSTFS_BUCKET', 'lumberjack-workbench');

            if ($disk->getClient()->doesBucketExistV2($bucket)) {
                $this->info("Bucket [{$bucket}] already exists.");

                return 0;
            }

            $disk->getClient()->createBucket(['Bucket' => $bucket]);
            $this->info("Created bucket [{$bucket}].");

            return 0;
        })->purpose('Create the RustFS bucket the disk:rustfs check writes to');

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('demo:report')->everyMinute();
            $schedule->command('demo:broken')->everyFiveMinutes();
            $schedule->call(fn () => DB::select('select 2 as cleanup'))->everyTwoMinutes()->description('demo cleanup');
        });

        // Health checks for the Health page, run by the scheduler every minute.
        Lumberjack::healthChecks()
            ->database()
            ->database('mysql', warnAtConnections: 0.8)
            ->database('mariadb', warnAtConnections: 0.8)
            ->database('pgsql', warnAtConnections: 0.8)
            ->cache()
            ->disk('local')
            // Every 5 minutes instead of every minute, to show a check with its own interval.
            ->disk('rustfs')->every(5)
            // The bulk queue no worker runs: the demo's Flood button puts 1200 jobs on it.
            ->queue('database', queue: 'bulk', warnAt: 100, failAt: 1000)
            ->debugMode(environments: ['production'])
            ->check('payments-api', fn (): bool => ! is_file(self::path('storage/payments-down')), 2, 3);

        Event::listen(HealthStateChanged::class, LogHealthStateChange::class);

        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
    }

    /** A path inside workbench/, where the demo keeps its state. */
    public static function path(string $path): string
    {
        return dirname(__DIR__, 3).'/workbench/'.$path;
    }

    private function database(): string
    {
        $path = self::path('storage/database.sqlite');

        if (! is_file($path)) {
            @mkdir(dirname($path), 0775, true);
            touch($path);
        }

        return $path;
    }
}
