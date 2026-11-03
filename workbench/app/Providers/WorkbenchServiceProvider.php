<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Dotenv\Dotenv;
use ExeQue\Lumberjack\Facades\Lumberjack;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

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

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('demo:report')->everyMinute();
            $schedule->command('demo:broken')->everyFiveMinutes();
            $schedule->call(fn () => DB::select('select 2 as cleanup'))->everyTwoMinutes()->description('demo cleanup');
        });

        // Health checks for the Health page, run by the scheduler every minute.
        Lumberjack::health()
            ->database()
            ->cache()
            ->disk('local')
            ->debugMode(environments: ['production'])
            ->check('payments-api', fn (): bool => ! is_file(self::path('storage/payments-down')), 2, 3);

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
