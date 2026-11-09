<?php

declare(strict_types=1);

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class ShipOrder implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $orderId, public string $carrier, public array $items, public ?DateTimeImmutable $at = null)
    {
    }
}

class ChargeCard implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public string $cardNumber)
    {
    }
}

function queued(object $job): JobQueued
{
    return new JobQueued('redis', 'default', '1', $job, (string) json_encode(['uuid' => 'a1b2c3d4-0000-4000-8000-000000000001', 'displayName' => $job::class]), null);
}

describe('job parameters', function () {
    it('sends the public properties of a queued job, without the queue plumbing', function () {
        event(queued(new ShipOrder(42, 'gls', range(1, 15), new DateTimeImmutable('2026-11-02T12:00:00+00:00'))));
        app()->terminate();

        $parameters = $this->sentEvents('job')[0]['parameters'];

        expect($parameters)->toMatchArray(['orderId' => 42, 'carrier' => 'gls', 'at' => '2026-11-02T12:00:00+00:00'])
            ->and($parameters['items'])->toHaveCount(11)
            ->and($parameters)->not->toHaveKeys(['queue', 'connection', 'delay', 'chained', 'debounceOwner', 'uniqueLockOwner']);
    });

    it('sends no parameters for an encrypted job, or when they are turned off', function () {
        event(queued(new ChargeCard('4111111111111111')));
        app()->terminate();
        expect($this->sentEvents('job')[0])->not->toHaveKey('parameters');

        $this->withConfig(['lumberjack.jobs.parameters' => false]);
        event(queued(new ShipOrder(7, 'dao', [])));
        app()->terminate();
        expect($this->sentEvents('job')[0])->not->toHaveKey('parameters');
    });
});

describe('scheduled tasks', function () {
    it('sends the command beside the description that names the task', function () {
        event(new ScheduledTaskSkipped(app(Schedule::class)->command('reports:send')->description('Send the reports')->hourly()));

        expect($this->sentEvents('schedule')[0])->toMatchArray(['name' => 'Send the reports', 'command' => 'artisan reports:send']);
    });

    beforeEach(fn () => $this->task = app(Schedule::class)->command('reports:send')->hourly());

    it('reports a run with its own trace, its queries, duration and next run', function () {
        event(new ScheduledTaskStarting($this->task));
        DB::select('select 1');
        $this->task->exitCode = 0;
        event(new ScheduledTaskFinished($this->task, 0.25));

        $events = $this->sentEvents();
        [$started, $query, $finished] = $events;

        expect(array_column($events, 'type'))->toBe(['schedule', 'query', 'schedule'])
            ->and($started)->toMatchArray(['status' => 'started', 'name' => 'artisan reports:send', 'expression' => '0 * * * *'])
            ->and($finished['status'])->toBe('finished')
            ->and($finished['uuid'])->toBe($started['uuid'])
            ->and($finished['exit_code'])->toBe(0)
            ->and($finished['next_run_at'])->toBeString()
            ->and($query['trace_id'])->toBe($started['trace_id']);
    });

    it('sends a failed run with its exception once, not again as a reported exception', function () {
        $exception = new RuntimeException('Report server down');

        event(new ScheduledTaskStarting($this->task));
        event(new ScheduledTaskFailed($this->task, $exception));
        report($exception);
        app()->terminate();

        expect($this->sentEvents('schedule')[1])->toMatchArray(['status' => 'failed'])
            ->and($this->sentEvents('schedule')[1]['exception']['message'])->toBe('Report server down')
            ->and($this->sentEvents('exception'))->toBe([]);
    });

    it('does not send the exception Laravel throws after a command task exits non-zero', function () {
        event(new ScheduledTaskStarting($this->task));
        $this->task->exitCode = 1;
        event(new ScheduledTaskFinished($this->task, 0.1));
        $exception = new Exception("Scheduled command ['php' 'artisan' reports:send] failed with exit code [1].");
        event(new ScheduledTaskFailed($this->task, $exception));
        report($exception);
        app()->terminate();

        expect($this->sentEvents('schedule')[1])->toMatchArray(['status' => 'finished', 'exit_code' => 1])
            ->and($this->sentEvents('exception'))->toBe([]);
    });

    it('reports a skipped run, and nothing for an ignored task', function () {
        $this->withConfig(['lumberjack.schedule.ignore' => ['artisan horizon:*']]);
        $schedule = app(Schedule::class);

        event(new ScheduledTaskSkipped($schedule->command('reports:send')->hourly()));
        event(new ScheduledTaskSkipped($schedule->command('horizon:snapshot')->everyFiveMinutes()));

        expect(array_column($this->sentEvents('schedule'), 'name'))->toBe(['artisan reports:send']);
    });
});

describe('requests by status', function () {
    it('leaves out a request with an ignored status, and its queries', function () {
        $this->withConfig(['lumberjack.requests.ignore_status' => [404]]);
        Route::get('/missing', fn () => DB::select('select 1') ? abort(404) : null);

        $this->get('/missing')->assertNotFound();
        app()->terminate();

        expect($this->sentEvents('request'))->toBe([])->and($this->sentEvents('query'))->toBe([]);
    });
});
