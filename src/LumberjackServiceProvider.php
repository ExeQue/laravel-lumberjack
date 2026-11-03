<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack;

use Closure;
use ExeQue\Lumberjack\Console\AgentCommand;
use ExeQue\Lumberjack\Console\HealthCommand;
use ExeQue\Lumberjack\Console\InstallCommand;
use ExeQue\Lumberjack\Console\TerminateCommand;
use ExeQue\Lumberjack\Console\TestCommand;
use ExeQue\Lumberjack\Health\HealthRegistry;
use ExeQue\Lumberjack\Health\HealthStates;
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
use ExeQue\Lumberjack\Support\Safely;
use ExeQue\Lumberjack\Support\Scrubber;
use ExeQue\Lumberjack\Webhooks\Handlers;
use ExeQue\Lumberjack\Webhooks\WebhookController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Throwable;

class LumberjackServiceProvider extends ServiceProvider
{
    /** How long a health run may hold the scheduler's overlap lock. */
    public const int HEALTH_LOCK_MINUTES = 5;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lumberjack.php', 'lumberjack');

        $this->app->singleton(AgentClient::class, fn (): AgentClient => new AgentClient(
            $this->string('agent.host') ?? '127.0.0.1',
            (int) config('lumberjack.agent.port', 2407),
            key: ProjectFingerprint::agentKey($this->string('token'), $this->string('agent.key')),
        ));

        $this->app->singleton(RecordingRules::class, fn (): RecordingRules => RecordingRules::fromConfig((array) config('lumberjack', [])));

        $this->app->singleton(Scrubber::class, fn (): Scrubber => Scrubber::fromConfig((array) config('lumberjack.scrub', [])));

        $this->app->singleton(ExceptionContext::class, fn (Application $app): ExceptionContext => new ExceptionContext($app->make(Scrubber::class)));

        $this->app->singleton(Recorder::class, fn (Application $app): Recorder => new Recorder(
            $app->make(AgentClient::class),
            new ExceptionPresenter($this->string('base_path') ?? $app->basePath()),
            $app->make(ExceptionContext::class),
            $app->make(RecordingRules::class),
            [
                'environment' => $this->string('environment'),
                // Worked out at the first flush, not on every boot.
                'release' => fn (): ?string => $app->make('lumberjack.release'),
                'project' => ProjectFingerprint::of($this->string('token')),
            ],
            (bool) config('lumberjack.enabled', true),
            $app->make(Scrubber::class),
        ));

        // LUMBERJACK_RELEASE, else what the configured resolver works out.
        $this->app->singleton('lumberjack.release', function (Application $app): ?string {
            if ($this->string('release') !== null) {
                return $this->string('release');
            }

            try {
                return StackReleaseResolver::make($app, config('lumberjack.release_resolver'), $this->string('base_path') ?? $app->basePath())?->resolve();
            } catch (Throwable $e) {
                Safely::problem('Lumberjack could not work out the release: '.$e->getMessage());

                return null;
            }
        });

        $this->app->singleton(Handlers::class);

        $this->app->singleton(HealthRegistry::class, fn (Application $app): HealthRegistry => new HealthRegistry($app, (array) config('lumberjack.health.checks', [])));

        $this->app->singleton(HealthStates::class, fn (Application $app): HealthStates => new HealthStates(
            $app->make(CacheFactory::class),
            $app->make(HttpFactory::class),
            $app->make(Dispatcher::class),
            $this->string('url') ?? '',
            $this->string('token'),
            $this->string('environment'),
            $this->string('health.states.store'),
            (int) config('lumberjack.health.states.ttl', 30),
            (bool) config('lumberjack.health.states.pull', true),
            (int) config('lumberjack.health.states.memo', 5),
            // In a web request a pull waits until the response is sent.
            $app->runningInConsole() ? null : fn (Closure $pull) => $app->terminating(Safely::wrap($pull)),
        ));

        $this->app->singleton(BrowserScript::class, fn (Application $app): BrowserScript => new BrowserScript(
            $app->make(Recorder::class),
            $this->string('url') ?? '',
            $this->string('public_key'),
            $this->string('environment'),
            (bool) config('lumberjack.announce', false),
            (bool) config('lumberjack.browser_user', false),
        ));
    }

    public function boot(): void
    {
        $enabled = (bool) config('lumberjack.enabled', true);

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/lumberjack.php' => config_path('lumberjack.php')], 'lumberjack-config');
            // Also when turned off: they say so and do nothing.
            $this->commands([AgentCommand::class, TerminateCommand::class, TestCommand::class, HealthCommand::class, InstallCommand::class]);
        }

        // Also when turned off, so @lumberjack still compiles (to nothing) and a log channel
        // with the lumberjack driver still works (and drops what it gets).
        $this->callAfterResolving('blade.compiler', function (BladeCompiler $blade): void {
            // @lumberjack, or @lumberjack($nonce) for a Content-Security-Policy with nonces.
            $blade->directive('lumberjack', fn (string $nonce = ''): string => '<?php echo app(\\'.BrowserScript::class.'::class)->render('.$nonce.'); ?>');
        });

        $this->callAfterResolving('log', function (LogManager $log) use ($enabled): void {
            $log->extend('lumberjack', fn (Application $app, array $config): Logger => new Logger('lumberjack', [
                $enabled ? new LumberjackHandler($app->make(Recorder::class), $config['channel'] ?? null, $config['level'] ?? 'debug') : new NullHandler(),
            ]));
        });

        // Off means off: no listeners, no scheduled checks, no webhook route, nothing resolved.
        if (! $enabled) {
            return;
        }

        if ($this->app->runningInConsole()) {
            $this->scheduleHealthChecks();
        }

        // Only when turned on. Outside the web middleware group, so no session or CSRF;
        // the signature is the check.
        if ((bool) config('lumberjack.webhook.enabled', false)) {
            Route::post($this->string('webhook.path') ?? 'lumberjack/webhook', WebhookController::class)->name('lumberjack.webhook');
        }

        // Config the app wrote (rules, scrub patterns, release resolvers) is read here; a
        // mistake in it costs the recording, never the app's boot.
        Safely::run($this->record(...));
    }

    /**
     * Subscribes the listeners that record.
     */
    private function record(): void
    {
        $recorder = $this->app->make(Recorder::class);
        $rules = $this->app->make(RecordingRules::class);

        if ($rules->exceptions) {
            $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) use ($recorder): void {
                if (method_exists($handler, 'reportable')) {
                    $handler->reportable(function (Throwable $e) use ($recorder): void {
                        Safely::run($recorder->exception(...), $e);
                    });
                }
            });
        }

        $events = $this->app->make('events');
        $context = $this->app->make(ExceptionContext::class);
        $events->listen(RouteMatched::class, Safely::wrap(fn (RouteMatched $e) => $context->serving($e->request)));
        // Octane's workers run in the console; exceptions still carry the request being served.
        $events->listen(RecordsLaravelEvents::OCTANE_REQUEST_RECEIVED, Safely::wrap(fn (object $e) => $context->serving(property_exists($e, 'request') && $e->request instanceof Request ? $e->request : null)));
        $events->listen(RecordsLaravelEvents::OCTANE_REQUEST_TERMINATED, Safely::wrap(function () use ($context, $recorder): void {
            $context->serving(null);
            $recorder->flush();
        }));

        $events->subscribe(new RecordsLaravelEvents($recorder, $rules, new CallerLocation($this->string('base_path') ?? $this->app->basePath(), __DIR__)));
        $events->subscribe(new RecordsScheduledTasks($recorder, $rules));
        $events->subscribe(new RecordsSpans($recorder, (array) config('lumberjack.spans', [])));

        // Redis only reports its commands when asked to.
        if (config('lumberjack.spans.redis') && $this->app->bound('redis')) {
            $this->app->make('redis')->enableEvents();
        }

        $events->subscribe($commands = new RecordsCommands($recorder, $rules, $this->scheduledCommands(...)));

        // Inside the app's terminate: whatever goes wrong here stays here.
        $this->app->terminating(Safely::wrap($commands->finishOpen(...)));
        $this->app->terminating(Safely::wrap($recorder->flush(...)));
    }

    /**
     * Runs lumberjack:health from the app's scheduler, once checks are registered. A
     * broken health config never stops the app's own scheduled tasks.
     */
    private function scheduleHealthChecks(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            try {
                $cron = $this->string('health.schedule');

                if ($cron === null || $this->app->make(HealthRegistry::class)->isEmpty()) {
                    return;
                }

                // The overlap lock expires after a few minutes, not Laravel's default day: a run
                // killed mid-way (a deploy, the OOM killer) never releases it, and checks would
                // stop for a day. Checks time out long before this.
                $event = $schedule->command(HealthCommand::class)->cron($cron)->withoutOverlapping(self::HEALTH_LOCK_MINUTES)->runInBackground();

                if ((bool) config('lumberjack.health.one_server', true)) {
                    $event->onOneServer();
                }
            } catch (Throwable $e) {
                Safely::problem('Lumberjack could not schedule its health checks: '.$e->getMessage());
            }
        });
    }

    private function string(string $key): ?string
    {
        $value = config('lumberjack.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Whether the app's scheduler runs this command, so its run takes the scheduler's
     * sample rate. Looked up once per process, and only when a command starts.
     */
    private function scheduledCommands(string $name): bool
    {
        static $names = null;

        if ($names === null) {
            try {
                $names = [];

                foreach ($this->app->make(Schedule::class)->events() as $event) {
                    if (is_string($event->command) && preg_match("/artisan'?\\s+'?([^\\s']+)/", $event->command, $match) === 1) {
                        $names[$match[1]] = true;
                    }
                }
            } catch (Throwable) {
                $names = [];
            }
        }

        return isset($names[$name]);
    }
}
