<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Contracts\HasHealthCheckInterval;
use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Exceptions\InvalidHealthCheckIntervalException;
use ExeQue\Lumberjack\Exceptions\InvalidHealthCheckSlugException;
use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Checks\CacheHealthCheck;
use ExeQue\Lumberjack\Health\Checks\DatabaseHealthCheck;
use ExeQue\Lumberjack\Health\Checks\DebugModeHealthCheck;
use ExeQue\Lumberjack\Health\Checks\DiskHealthCheck;
use ExeQue\Lumberjack\Health\Checks\FailedJobsHealthCheck;
use ExeQue\Lumberjack\Health\Checks\FreshFileHealthCheck;
use ExeQue\Lumberjack\Health\Checks\HorizonHealthCheck;
use ExeQue\Lumberjack\Health\Checks\HttpHealthCheck;
use ExeQue\Lumberjack\Health\Checks\MailHealthCheck;
use ExeQue\Lumberjack\Health\Checks\MigrationsHealthCheck;
use ExeQue\Lumberjack\Health\Checks\QueueHealthCheck;
use ExeQue\Lumberjack\Health\Checks\RedisHealthCheck;
use ExeQue\Lumberjack\Health\CheckStatus;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;
use ExeQue\Lumberjack\Health\HealthRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class StripeConnectionCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $downAfter = 5,
        private readonly bool $healthy = true,
        private readonly int $timeout = 10,
        private readonly ?string $slug = null,
    ) {
    }

    public function run(): CheckResult
    {
        return $this->healthy ? CheckResult::ok() : CheckResult::failed('Stripe is down');
    }
}

final class HourlyCheck implements HasHealthCheckInterval, HealthCheck
{
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $downAfter = 5,
        private readonly int $timeout = 10,
        private readonly ?string $slug = 'hourly',
    ) {
    }

    public function run(): CheckResult
    {
        return CheckResult::ok();
    }

    public function everyMinutes(): ?int
    {
        return 60;
    }
}

/** Counts how often the registry makes it. */
final class CountedCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    public static int $made = 0;

    public function __construct(
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $downAfter = 5,
        private readonly int $timeout = 10,
        private readonly ?string $slug = 'counted',
    ) {
        self::$made++;
    }

    public function run(): CheckResult
    {
        return CheckResult::ok();
    }
}

/** Written against the HealthCheck contract alone: no trait, no interval. */
final class BareCheck implements HealthCheck
{
    public function slug(): string
    {
        return 'bare';
    }

    public function run(): CheckResult
    {
        return CheckResult::ok();
    }

    public function degradeAfter(): int
    {
        return 2;
    }

    public function recoverAfter(): int
    {
        return 5;
    }

    public function downAfter(): int
    {
        return 5;
    }

    public function timeout(): int
    {
        return 10;
    }
}

function option(object $check, string $property): mixed
{
    return (new ReflectionProperty($check, $property))->getValue($check);
}

function healthEvents(): array
{
    return test()->sentEvents('health');
}

describe('registry', function () {
    it('maps every fluent method to its check with the given parameters', function () {
        $checks = Lumberjack::healthChecks()
            ->disk('s3', 3, 5, 6)
            ->disk('local', warnAt: 80, failAt: 90, slug: 'local-disk')
            ->database(maxLatencyMs: 200)
            ->cache()
            ->redis()
            ->queue('sync', warnAt: 500, failAt: 2000)
            ->horizon()
            ->http('https://Example.com/up', maxLatencyMs: 1000)
            ->debugMode()
            ->migrations()
            ->failedJobs(withinMinutes: 30)
            ->freshFile('backups', 'db/daily', maxAgeMinutes: 1440)
            ->mail()
            ->check('stripe', fn () => true, 3, 4, 6)
            ->checks();

        expect(array_map(fn (HealthCheck $check): string => $check::class, $checks))->toBe([
            'disk:s3' => DiskHealthCheck::class,
            'local-disk' => DiskHealthCheck::class,
            'database:testing' => DatabaseHealthCheck::class,
            'cache:array' => CacheHealthCheck::class,
            'redis:default' => RedisHealthCheck::class,
            'queue:sync:default' => QueueHealthCheck::class,
            'horizon' => HorizonHealthCheck::class,
            'http:example.com-up' => HttpHealthCheck::class,
            'debug-mode' => DebugModeHealthCheck::class,
            'migrations:testing' => MigrationsHealthCheck::class,
            'failed-jobs' => FailedJobsHealthCheck::class,
            'fresh:backups:db-daily' => FreshFileHealthCheck::class,
            'mail:'.config('mail.default') => MailHealthCheck::class,
            'stripe' => ExeQue\Lumberjack\Health\ClosureHealthCheck::class,
        ])
            ->and([$checks['disk:s3']->degradeAfter(), $checks['disk:s3']->recoverAfter(), $checks['disk:s3']->downAfter()])->toBe([3, 5, 6])
            ->and($checks['local-disk']->downAfter())->toBe(5)
            ->and([option($checks['local-disk'], 'warnAt'), option($checks['local-disk'], 'failAt')])->toBe([80, 90])
            ->and(option($checks['database:testing'], 'maxLatencyMs'))->toBe(200)
            ->and(option($checks['http:example.com-up'], 'maxLatencyMs'))->toBe(1000)
            ->and(option($checks['failed-jobs'], 'withinMinutes'))->toBe(30)
            ->and(option($checks['fresh:backups:db-daily'], 'maxAgeMinutes'))->toBe(1440)
            ->and([$checks['stripe']->degradeAfter(), $checks['stripe']->recoverAfter(), $checks['stripe']->downAfter(), $checks['stripe']->timeout()])->toBe([3, 4, 6, 10]);
    });

    it('merges config and code checks, a later one replacing an earlier one with the same slug', function () {
        $this->withConfig(['lumberjack.health.checks' => [
            DatabaseHealthCheck::class,
            [StripeConnectionCheck::class, 'healthy' => false],
        ]]);

        Lumberjack::healthChecks()->add([StripeConnectionCheck::class, 'degradeAfter' => 7], new CacheHealthCheck());

        $checks = app(HealthRegistry::class)->checks();

        expect(array_keys($checks))->toBe(['database:testing', 'stripe-connection-check', 'cache:array'])
            ->and($checks['stripe-connection-check']->degradeAfter())->toBe(7)
            ->and($checks['stripe-connection-check']->run()->status)->toBe(CheckStatus::Ok);
    });

    it('takes one check per argument to add(), so a list is spread and [class, parameters] is one check', function () {
        $list = [DatabaseHealthCheck::class, [DiskHealthCheck::class, 'disk' => 's3', 'degradeAfter' => 4]];

        $checks = Lumberjack::healthChecks()->add(...$list)->add(new CacheHealthCheck(), [StripeConnectionCheck::class, 'slug' => 'stripe'])->checks();

        expect(array_keys($checks))->toBe(['database:testing', 'disk:s3', 'cache:array', 'stripe'])
            ->and($checks['disk:s3']->degradeAfter())->toBe(4);
    });

    it('refuses an invalid slug when the check is registered', function () {
        expect(fn () => Lumberjack::healthChecks()->disk('s3', slug: 'Media Bucket'))->toThrow(InvalidHealthCheckSlugException::class)
            ->and(fn () => Lumberjack::healthChecks()->check('-stripe', fn () => true))->toThrow(InvalidHealthCheckSlugException::class)
            ->and(fn () => Lumberjack::healthChecks()->add([CacheHealthCheck::class, 'slug' => str_repeat('a', 101)]))->toThrow(InvalidHealthCheckSlugException::class);
    });
});

describe('intervals', function () {
    function intervals(): array
    {
        $registry = app(HealthRegistry::class);

        return array_map(fn (HealthCheck $check): ?int => $registry->everyMinutes($check), $registry->checks());
    }

    it('applies every() to the check added just before it, from a fluent method, add() or a config entry', function () {
        $this->withConfig(['lumberjack.health.checks' => [
            [DiskHealthCheck::class, 'disk' => 's3', 'every' => 15],
            CacheHealthCheck::class,
        ]]);

        Lumberjack::healthChecks()
            ->disk('local')->every(5)
            ->add(DatabaseHealthCheck::class, [StripeConnectionCheck::class, 'slug' => 'stripe'])->every(10)
            ->add(HourlyCheck::class)
            ->add([HourlyCheck::class, 'slug' => 'hourly-overridden'])->every(30);

        expect(intervals())->toBe([
            'disk:s3' => 15,
            'cache:array' => null,
            'disk:local' => 5,
            'database:testing' => null,
            'stripe' => 10,
            'hourly' => 60,
            'hourly-overridden' => 30,
        ]);
    });

    it('runs a check that implements only the HealthCheck contract on every run, or as every() says', function () {
        Lumberjack::healthChecks()->add(new BareCheck());

        $this->artisan('lumberjack:health')->assertExitCode(0);
        $this->artisan('lumberjack:health')->assertExitCode(0);

        expect(intervals())->toBe(['bare' => null])
            ->and(array_column(healthEvents(), 'interval_minutes', 'slug'))->toBe(['bare' => null])
            ->and(healthEvents())->toHaveCount(2);

        Lumberjack::healthChecks()->add(new BareCheck())->every(10);

        expect(intervals())->toBe(['bare' => 10]);
    });

    it('refuses every() before any check was added in code, and fewer minutes than the schedule runs', function () {
        $this->withConfig(['lumberjack.health.checks' => [CacheHealthCheck::class], 'lumberjack.health.schedule' => '*/5 * * * *']);

        expect(fn () => Lumberjack::healthChecks()->every(15))->toThrow(InvalidHealthCheckIntervalException::class, 'call it right after add()')
            ->and(fn () => Lumberjack::healthChecks()->cache('file')->every(3))->toThrow(InvalidHealthCheckIntervalException::class, 'at least 5')
            ->and(fn () => Lumberjack::healthChecks()->cache('file')->every(0))->toThrow(InvalidHealthCheckIntervalException::class)
            ->and(Lumberjack::healthChecks()->cache('file')->every(7))->toBeInstanceOf(HealthRegistry::class);
    });

    it('skips a config entry with an every that is not whole minutes or below the schedule', function () {
        $this->withConfig(['lumberjack.health.checks' => [
            [DiskHealthCheck::class, 'disk' => 's3', 'every' => '15'],
            [DiskHealthCheck::class, 'disk' => 'local', 'every' => 0],
            CacheHealthCheck::class,
        ]]);

        expect(intervals())->toBe(['cache:array' => null]);
    });

    it('skips a check until its interval passed, runs every check with --all and sends interval_minutes', function () {
        $this->travelTo(now()->startOfHour());
        Lumberjack::healthChecks()->check('often', fn () => true)->check('rare', fn () => true)->every(15);

        $this->artisan('lumberjack:health')->assertExitCode(0);
        $this->travel(1)->minutes();
        $this->artisan('lumberjack:health')->expectsOutputToContain('Not due, runs every 15 min')->assertExitCode(0);
        $this->travel(13)->minutes();
        $this->travel(40)->seconds();
        // 14 minutes 40 seconds after it last ran: a tick's few seconds late is still on time.
        $this->artisan('lumberjack:health')->assertExitCode(0);
        $this->artisan('lumberjack:health', ['--all' => true])->assertExitCode(0);
        $this->artisan('lumberjack:health', ['--check' => 'rare'])->assertExitCode(0);
        $this->artisan('lumberjack:health')->assertExitCode(0);

        $events = healthEvents();

        expect(array_column($events, 'slug'))->toBe(['often', 'rare', 'often', 'often', 'rare', 'often', 'rare', 'rare', 'often'])
            ->and(array_column($events, 'interval_minutes', 'slug'))->toBe(['often' => null, 'rare' => 15]);
    });
});

describe('lumberjack:health', function () {
    it('runs every check, records one health event each and exits 1 when one failed', function () {
        Lumberjack::healthChecks()
            ->check('ok-bool', fn () => true, 3, 4)
            ->check('failed-bool', fn () => false)
            ->check('warning', fn () => CheckResult::warning('Getting full', ['used_percent' => 85.5]))
            ->check('exploding', fn () => throw new RuntimeException('Connection refused'));

        $this->artisan('lumberjack:health')
            ->expectsOutputToContain('Getting full')
            ->expectsOutputToContain('Connection refused')
            ->assertExitCode(1);

        $events = collect(healthEvents())->keyBy('slug');

        expect($events->map(fn (array $event): string => $event['status'])->all())->toBe([
            'ok-bool' => 'ok', 'failed-bool' => 'failed', 'warning' => 'warning', 'exploding' => 'failed',
        ])
            ->and($events['ok-bool'])->toMatchArray(['type' => 'health', 'message' => null, 'degrade_after' => 3, 'recover_after' => 4])
            ->and($events['ok-bool'])->toHaveKeys(['duration_ms', 'trace_id', 'timestamp', 'metrics'])
            ->and($events['warning']['metrics'])->toEqual((object) ['used_percent' => 85.5])
            ->and($events['exploding']['message'])->toBe('Connection refused');
    });

    it('exits 0 with --no-fail and runs a single check with --check', function () {
        Lumberjack::healthChecks()->check('down', fn () => false)->check('up', fn () => true);

        $this->artisan('lumberjack:health', ['--no-fail' => true])->assertExitCode(0);
        $this->artisan('lumberjack:health', ['--check' => 'up'])->assertExitCode(0);
        $this->artisan('lumberjack:health', ['--check' => 'missing'])->assertExitCode(1);

        expect(array_column(healthEvents(), 'slug'))->toBe(['down', 'up', 'up']);
    });

    it('fails a check that runs past its timeout', function () {
        Lumberjack::healthChecks()->check('slow', function () {
            sleep(3);

            return true;
        }, timeout: 1);

        $this->artisan('lumberjack:health')->assertExitCode(1);

        expect(healthEvents()[0])->toMatchArray(['status' => 'failed', 'message' => 'Timed out after 1 s.'])
            ->and(healthEvents()[0]['duration_ms'])->toBeLessThan(2500);
    });
});

describe('scheduling', function () {
    function healthTasks(): array
    {
        return array_values(array_filter(app(Schedule::class)->events(), fn ($event): bool => str_contains((string) $event->command, 'lumberjack:health')));
    }

    it('is not scheduled without checks or without a schedule', function () {
        expect(healthTasks())->toBe([]);

        $this->withConfig(['lumberjack.health.checks' => [CacheHealthCheck::class], 'lumberjack.health.schedule' => null]);

        expect(healthTasks())->toBe([]);
    });

    it('runs in the background on one server on the configured schedule', function () {
        $this->withConfig(['lumberjack.health.checks' => [CacheHealthCheck::class], 'lumberjack.health.schedule' => '*/5 * * * *']);

        [$task] = healthTasks();

        expect($task->expression)->toBe('*/5 * * * *')
            ->and($task->runInBackground)->toBeTrue()
            ->and($task->withoutOverlapping)->toBeTrue()
            ->and($task->expiresAt)->toBe(5)
            ->and($task->onOneServer)->toBeTrue();
    });

    it('is scheduled for checks registered in code, on every server when one_server is off', function () {
        $this->withConfig(['lumberjack.health.one_server' => false]);
        Lumberjack::healthChecks()->cache();

        [$task] = healthTasks();

        expect($task->onOneServer)->toBeFalse();
    });
});

describe('built-in checks', function () {
    it('checks a disk by writing, reading and deleting a file', function () {
        Storage::fake('local');

        $result = (new DiskHealthCheck('local'))->run();

        expect($result->status)->toBe(CheckStatus::Ok)
            ->and($result->metrics)->toHaveKeys(['latency_ms', 'used_percent'])
            ->and(Storage::disk('local')->allFiles())->toBe([])
            ->and((new DiskHealthCheck('local', warnAt: 0))->run()->status)->toBe(CheckStatus::Warning)
            ->and((new DiskHealthCheck('local', warnAt: 0, failAt: 0))->run()->status)->toBe(CheckStatus::Failed)
            ->and((new DiskHealthCheck('nowhere'))->run())->toMatchObject(['status' => CheckStatus::Failed, 'message' => 'Disk [nowhere] is not configured.']);
    });

    it('checks the database, warning when it is slow', function () {
        expect((new DatabaseHealthCheck())->run()->status)->toBe(CheckStatus::Ok)
            ->and((new DatabaseHealthCheck(maxLatencyMs: -1))->run()->status)->toBe(CheckStatus::Warning);

        expect((new DatabaseHealthCheck())->run()->metrics)->toHaveKey('latency_ms');
    });

    it('reports the size of an SQLite database file', function () {
        $file = tempnam(sys_get_temp_dir(), 'lj-db');
        config(['database.connections.lj_file' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '']]);
        DB::connection('lj_file')->statement('create table t (id integer)');

        $metrics = (new DatabaseHealthCheck('lj_file'))->run()->metrics;
        unlink($file);

        expect($metrics['size_bytes'])->toBeGreaterThan(0)
            ->and($metrics)->not->toHaveKey('connections');
    });

    it('checks the cache', function () {
        expect((new CacheHealthCheck())->run()->status)->toBe(CheckStatus::Ok);
    });

    it('pings redis', function (mixed $reply, CheckStatus $status) {
        Redis::shouldReceive('connection->command')->with('ping')->andReturn($reply);

        expect((new RedisHealthCheck())->run()->status)->toBe($status);
    })->with([
        'phpredis' => [true, CheckStatus::Ok],
        'no answer' => [false, CheckStatus::Failed],
    ]);

    it('checks the size of a queue', function () {
        Queue::fake();
        Queue::push('first');
        Queue::push('second');

        $result = (new QueueHealthCheck(warnAt: 2))->run();

        expect($result->status)->toBe(CheckStatus::Warning)
            ->and($result->metrics)->toBe(['size' => 2])
            ->and((new QueueHealthCheck(failAt: 2))->run()->status)->toBe(CheckStatus::Failed)
            ->and((new QueueHealthCheck(warnAt: 3))->run()->status)->toBe(CheckStatus::Ok);
    });

    it('counts the workers of a queue, and fails when jobs wait with none', function () {
        Queue::fake();
        Queue::push('first');
        $connection = (string) config('queue.default');
        $queue = (string) (config("queue.connections.{$connection}.queue") ?? 'default');
        Cache::put("lumberjack:queue-workers:{$connection}:{$queue}", ['web-1:10' => now()->getTimestamp() + 60, 'web-1:11' => now()->getTimestamp() - 1]);

        $result = (new QueueHealthCheck())->run();

        expect($result->status)->toBe(CheckStatus::Ok)
            ->and($result->message)->toBe('1 waiting · 1 worker')
            ->and($result->metrics)->toBe(['size' => 1, 'workers' => 1]);

        Cache::put("lumberjack:queue-workers:{$connection}:{$queue}", ['web-1:10' => now()->getTimestamp() - 1]);

        expect((new QueueHealthCheck())->run())->toMatchObject(['status' => CheckStatus::Failed, 'message' => '1 waiting · 0 workers']);
    });

    it('fails horizon when it is not installed', function () {
        expect((new HorizonHealthCheck())->run())->toMatchObject(['status' => CheckStatus::Failed, 'message' => 'Horizon is not installed (laravel/horizon).']);
    });

    it('checks a url', function () {
        Http::fake([
            'up.test/*' => Http::response('ok'),
            'down.test/*' => Http::response('nope', 503),
            'gone.test/*' => Http::failedConnection('Could not resolve host'),
        ]);

        expect((new HttpHealthCheck('https://up.test/health'))->run()->status)->toBe(CheckStatus::Ok)
            ->and((new HttpHealthCheck('https://up.test/health', maxLatencyMs: -1))->run()->status)->toBe(CheckStatus::Warning)
            ->and((new HttpHealthCheck('https://down.test/health'))->run())->toMatchObject(['status' => CheckStatus::Failed, 'message' => 'Answered 503, expected 200.'])
            ->and((new HttpHealthCheck('https://down.test/health', status: 503))->run()->status)->toBe(CheckStatus::Ok)
            ->and((new HttpHealthCheck('https://gone.test/health'))->run()->status)->toBe(CheckStatus::Failed);

        (new HttpHealthCheck('https://up.test/health', headers: ['Authorization' => 'Bearer secret', 'X-Probe' => 'lumberjack']))->run();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://up.test/health'
            && $request->hasHeader('Authorization', 'Bearer secret')
            && $request->hasHeader('X-Probe', 'lumberjack'));
    });

    it('fails when debug mode is on in a listed environment', function () {
        config(['app.debug' => true]);

        expect((new DebugModeHealthCheck())->run()->status)->toBe(CheckStatus::Ok)
            ->and((new DebugModeHealthCheck(environments: ['testing']))->run()->status)->toBe(CheckStatus::Failed);

        config(['app.debug' => false]);

        expect((new DebugModeHealthCheck(environments: ['testing']))->run()->status)->toBe(CheckStatus::Ok);
    });

    it('fails while migrations are pending', function () {
        $path = sys_get_temp_dir().'/lumberjack-migrations-'.Str::random(8);
        mkdir($path);
        file_put_contents($path.'/2026_01_01_000000_create_widgets_table.php', '<?php return new class extends Illuminate\Database\Migrations\Migration { public function up(): void { Illuminate\Support\Facades\Schema::create("widgets", fn ($t) => $t->id()); } };');
        app('migrator')->path($path);

        expect((new MigrationsHealthCheck())->run()->message)->toBe('The migrations table does not exist.');

        app('migrator')->getRepository()->createRepository();

        expect((new MigrationsHealthCheck())->run())->toMatchObject(['status' => CheckStatus::Failed, 'message' => '1 pending migration.']);

        Artisan::call('migrate', ['--path' => $path, '--realpath' => true]);

        expect((new MigrationsHealthCheck())->run()->status)->toBe(CheckStatus::Ok);
    });

    it('counts recently failed jobs', function () {
        config(['queue.failed.database' => 'testing']);
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid');
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at');
        });

        foreach ([now()->subMinutes(5), now()->subMinutes(10), now()->subHours(3)] as $i => $at) {
            DB::table('failed_jobs')->insert(['uuid' => "u{$i}", 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => '', 'failed_at' => $at]);
        }

        $result = (new FailedJobsHealthCheck())->run();

        expect($result->status)->toBe(CheckStatus::Warning)
            ->and($result->metrics)->toBe(['count' => 2])
            ->and((new FailedJobsHealthCheck(failAt: 2))->run()->status)->toBe(CheckStatus::Failed)
            ->and((new FailedJobsHealthCheck(withinMinutes: 1))->run()->status)->toBe(CheckStatus::Ok);

        app()->instance('queue.failer', new NullFailedJobProvider());

        expect((new FailedJobsHealthCheck())->run()->status)->toBe(CheckStatus::Ok);
    });

    it('checks the newest file under a path is fresh', function () {
        config(['filesystems.disks.backups' => ['driver' => 'local', 'root' => storage_path('backups')]]);
        $disk = Storage::fake('backups');

        expect((new FreshFileHealthCheck('backups', 'db', 60))->run()->status)->toBe(CheckStatus::Failed);

        $disk->put('db/old.sql', '');
        touch($disk->path('db/old.sql'), time() - 7200);

        expect((new FreshFileHealthCheck('backups', 'db', 60))->run())->toMatchObject(['status' => CheckStatus::Failed, 'metrics' => ['age_minutes' => 120]]);

        $disk->put('db/nested/new.sql', '');

        expect((new FreshFileHealthCheck('backups', 'db', 60))->run()->status)->toBe(CheckStatus::Failed)
            ->and((new FreshFileHealthCheck('backups', 'db', 60, recursive: true))->run()->status)->toBe(CheckStatus::Ok);
    });

    it('leaves non-SMTP mailers alone and connects to an SMTP one without sending', function () {
        config(['mail.mailers.log' => ['transport' => 'log'], 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);

        expect((new MailHealthCheck('log'))->run())->toMatchObject(['status' => CheckStatus::Ok, 'message' => 'Mailer [log] is not SMTP; not checked.'])
            ->and(fn () => (new MailHealthCheck('smtp'))->run())->toThrow(Symfony\Component\Mailer\Exception\TransportException::class);
    });
});

describe('firing a check from the app', function () {
    it('runs a registered check now and sends it, at most once per slug in the cooldown', function () {
        Lumberjack::healthChecks()->disk('s3', 3, 5, 7);
        Storage::fake('s3');

        $result = Lumberjack::health('disk:s3')->run();
        $again = Lumberjack::health('disk:s3')->run();

        expect($result?->status)->toBe(CheckStatus::Ok)
            ->and($again)->toBeNull()
            ->and(healthEvents())->toHaveCount(1)
            ->and(healthEvents()[0])->toMatchArray(['slug' => 'disk:s3', 'status' => 'ok', 'degrade_after' => 3, 'recover_after' => 5, 'down_after' => 7]);
    });

    it('reports a result the app found itself, with the check\'s thresholds', function () {
        Lumberjack::healthChecks()->disk('s3', 3, 5);

        expect(Lumberjack::health('disk:s3')->report(CheckResult::failed('Reading invoice.pdf failed')))->toBeTrue()
            ->and(Lumberjack::health('disk:s3')->report(CheckResult::failed('again')))->toBeFalse()
            ->and(healthEvents())->toHaveCount(1)
            ->and(healthEvents()[0])->toMatchArray(['slug' => 'disk:s3', 'status' => 'failed', 'message' => 'Reading invoice.pdf failed', 'degrade_after' => 3, 'down_after' => 5]);
    });

    it('has shorthands for a failure, a warning and a success the app saw', function () {
        $this->withConfig(['lumberjack.health.cooldown' => 0]);
        Lumberjack::healthChecks()->disk('s3');
        $disk = Lumberjack::health('disk:s3');

        expect($disk->failed('Read failed', ['attempts' => 3]))->toBeTrue()
            ->and($disk->warning('Slow read'))->toBeTrue()
            // A success the app saw counts toward recover_after like a scheduled one.
            ->and($disk->ok('Read works again'))->toBeTrue()
            ->and(array_map(fn (array $e): array => [$e['status'], $e['message'], $e['recover_after']], healthEvents()))->toBe([
                ['failed', 'Read failed', 5],
                ['warning', 'Slow read', 5],
                ['ok', 'Read works again', 5],
            ])
            ->and((array) healthEvents()[0]['metrics'])->toBe(['attempts' => 3]);
    });

    it('lets every run through with a cooldown of 0, and keeps a custom cooldown', function () {
        Lumberjack::healthChecks()->check('stripe', fn () => true);

        config(['lumberjack.health.cooldown' => 0]);
        expect(Lumberjack::health('stripe')->run())->not->toBeNull()
            ->and(Lumberjack::health('stripe')->run())->not->toBeNull();

        config(['lumberjack.health.cooldown' => 5]);
        expect(Lumberjack::health('stripe')->run())->not->toBeNull();

        $this->travel(4)->seconds();
        expect(Lumberjack::health('stripe')->run())->toBeNull();

        $this->travel(2)->seconds();
        expect(Lumberjack::health('stripe')->run())->not->toBeNull();
    });

    it('makes the registered checks once, not on every fire, and again after a check is added', function () {
        CountedCheck::$made = 0;
        Lumberjack::healthChecks()->add(CountedCheck::class)->check('stripe', fn () => true);

        Lumberjack::health('stripe')->failed('down');
        Lumberjack::health('stripe')->failed('still down');
        Lumberjack::health('counted')->run();

        expect(CountedCheck::$made)->toBe(1);

        Lumberjack::healthChecks()->cache();
        Lumberjack::health('stripe')->failed('down again');

        expect(CountedCheck::$made)->toBe(2);
    });

    it('refuses a slug that is not registered when the handle is used, not when it is made', function () {
        $handle = Lumberjack::health('disk:nope');

        expect(fn () => $handle->run())->toThrow(InvalidArgumentException::class, 'No health check [disk:nope] is registered.')
            ->and(fn () => $handle->report(CheckResult::ok()))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $handle->ok())->toThrow(InvalidArgumentException::class)
            ->and(fn () => $handle->failed())->toThrow(InvalidArgumentException::class)
            ->and(fn () => $handle->warning())->toThrow(InvalidArgumentException::class);
    });
});

describe('running a check inside a queue worker', function () {
    it('keeps the job timeout alarm the worker set', function () {
        if (! function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl is not loaded.');
        }

        pcntl_alarm(120);

        app(ExeQue\Lumberjack\Health\HealthRunner::class)
            ->run(new ExeQue\Lumberjack\Health\ClosureHealthCheck('quick', fn () => true));

        $remaining = pcntl_alarm(0);

        expect($remaining)->toBeGreaterThanOrEqual(118)->toBeLessThanOrEqual(120);
    });
});
