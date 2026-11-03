<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack;

use ExeQue\Lumberjack\Console\AgentCommand;
use ExeQue\Lumberjack\Console\HealthCommand;
use ExeQue\Lumberjack\Console\TerminateCommand;
use ExeQue\Lumberjack\Console\TestCommand;
use ExeQue\Lumberjack\Health\HealthRegistry;
use ExeQue\Lumberjack\Listeners\RecordsCommands;
use ExeQue\Lumberjack\Listeners\RecordsLaravelEvents;
use ExeQue\Lumberjack\Listeners\RecordsScheduledTasks;
use ExeQue\Lumberjack\Listeners\RecordsSpans;
use ExeQue\Lumberjack\Logging\LumberjackHandler;
use ExeQue\Lumberjack\Release\StackReleaseResolver;
use ExeQue\Lumberjack\Support\CallerLocation;
use ExeQue\Lumberjack\Support\ExceptionContext;
use ExeQue\Lumberjack\Support\ExceptionPresenter;
use ExeQue\Lumberjack\Support\ProjectFingerprint;
use ExeQue\Lumberjack\Support\RecordingRules;
use ExeQue\Lumberjack\Webhooks\Handlers;
use ExeQue\Lumberjack\Webhooks\WebhookController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Monolog\Logger;
use Throwable;

class LumberjackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lumberjack.php', 'lumberjack');

        $this->app->singleton(AgentClient::class, fn (): AgentClient => new AgentClient(
            $this->string('agent.host') ?? '127.0.0.1',
            (int) config('lumberjack.agent.port', 2407),
        ));

        $this->app->singleton(RecordingRules::class, fn (): RecordingRules => RecordingRules::fromConfig((array) config('lumberjack', [])));

        $this->app->singleton(Recorder::class, fn (Application $app): Recorder => new Recorder(
            $app->make(AgentClient::class),
            new ExceptionPresenter($this->string('base_path') ?? $app->basePath()),
            new ExceptionContext($app, array_map(strtolower(...), $this->strings('scrub'))),
            $app->make(RecordingRules::class),
            [
                'environment' => $this->string('environment'),
                'release' => $app->make('lumberjack.release'),
                'project' => ProjectFingerprint::of($this->string('token')),
            ],
            (bool) config('lumberjack.enabled', true),
        ));

        // LUMBERJACK_RELEASE, else what the configured resolver works out.
        $this->app->singleton('lumberjack.release', function (Application $app): ?string {
            if ($this->string('release') !== null) {
                return $this->string('release');
            }

            return StackReleaseResolver::make($app, config('lumberjack.release_resolver'), $this->string('base_path') ?? $app->basePath())?->resolve();
        });

        $this->app->singleton(Handlers::class);

        $this->app->singleton(HealthRegistry::class, fn (Application $app): HealthRegistry => new HealthRegistry($app, (array) config('lumberjack.health.checks', [])));

        $this->app->singleton(BrowserScript::class, fn (Application $app): BrowserScript => new BrowserScript(
            $app->make(Recorder::class),
            $app->make('auth'),
            $this->string('url') ?? '',
            $this->string('public_key'),
            $this->string('environment'),
            $app->make('lumberjack.release'),
            (bool) config('lumberjack.announce', false),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/lumberjack.php' => config_path('lumberjack.php')], 'lumberjack-config');
            $this->commands([AgentCommand::class, TerminateCommand::class, TestCommand::class, HealthCommand::class]);
            $this->scheduleHealthChecks();
        }

        // Only when turned on. Outside the web middleware group, so no session or CSRF;
        // the signature is the check.
        if ((bool) config('lumberjack.webhook.enabled', false)) {
            Route::post($this->string('webhook.path') ?? 'lumberjack/webhook', WebhookController::class)->name('lumberjack.webhook');
        }

        Blade::directive('lumberjack', fn (): string => '<?php echo app(\\'.BrowserScript::class.'::class)->render(); ?>');

        $this->callAfterResolving('log', function (LogManager $log): void {
            $log->extend('lumberjack', fn (Application $app, array $config): Logger => new Logger('lumberjack', [
                new LumberjackHandler($app->make(Recorder::class), $config['channel'] ?? null, $config['level'] ?? 'debug'),
            ]));
        });

        $recorder = $this->app->make(Recorder::class);

        if (! $recorder->enabled()) {
            return;
        }

        $rules = $this->app->make(RecordingRules::class);

        if ($rules->exceptions) {
            $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) use ($recorder): void {
                if (method_exists($handler, 'reportable')) {
                    $handler->reportable(function (Throwable $e) use ($recorder): void {
                        $recorder->exception($e);
                    });
                }
            });
        }

        $this->app->make('events')->subscribe(new RecordsLaravelEvents($recorder, $rules, new CallerLocation($this->string('base_path') ?? $this->app->basePath(), __DIR__)));
        $this->app->make('events')->subscribe(new RecordsScheduledTasks($recorder, $rules));
        $this->app->make('events')->subscribe(new RecordsSpans($recorder, (array) config('lumberjack.spans', [])));

        // Redis only reports its commands when asked to.
        if (config('lumberjack.spans.redis') && $this->app->bound('redis')) {
            $this->app->make('redis')->enableEvents();
        }
        $this->app->make('events')->subscribe($commands = new RecordsCommands($recorder, $rules));
        $this->app->terminating(fn () => $commands->finishOpen());

        $this->app->terminating(fn () => $recorder->flush());
    }

    /**
     * Runs lumberjack:health from the app's scheduler, once checks are registered.
     */
    private function scheduleHealthChecks(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $cron = $this->string('health.schedule');

            if ($cron === null || $this->app->make(HealthRegistry::class)->isEmpty()) {
                return;
            }

            $event = $schedule->command(HealthCommand::class)->cron($cron)->withoutOverlapping()->runInBackground();

            if ((bool) config('lumberjack.health.one_server', true)) {
                $event->onOneServer();
            }
        });
    }

    private function string(string $key): ?string
    {
        $value = config('lumberjack.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function strings(string $key): array
    {
        return array_values(array_filter((array) config('lumberjack.'.$key, []), 'is_string'));
    }
}
