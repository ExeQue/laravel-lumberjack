<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Support\ProjectFingerprint;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Support\TestJob;

describe('exceptions', function () {
    it('sends a reported exception with frames, code and context in the batch envelope', function () {
        report(new RuntimeException('Card declined'));
        app()->terminate();

        $payload = $this->agent->payloads[0];
        expect($payload)->toMatchArray(['v' => 1, 'environment' => 'testing', 'release' => 'abc123'])
            ->and($payload['sdk'])->toBe(['name' => 'lumberjack.php.laravel', 'version' => ExeQue\Lumberjack\Support\Client::version()])
            // The agent turns away data of another project by this mark; the token itself stays in the app.
            ->and($payload['project'])->toBe(ProjectFingerprint::of('secret-token'))
            ->and(json_encode($payload))->not->toContain('secret-token');

        $exception = $this->sentEvents('exception')[0];
        expect($exception['class'])->toBe(RuntimeException::class)
            ->and($exception['message'])->toBe('Card declined')
            ->and($exception['trace_id'])->toHaveLength(32)
            ->and($exception['stacktrace'][0]['file'])->toBe($exception['file'])
            ->and($exception['stacktrace'][0]['line'])->toBe($exception['line']);
    });

    it('skips ignored exception classes', function () {
        config()->set('lumberjack.exceptions.ignore', [InvalidArgumentException::class]);
        app()->forgetInstance(ExeQue\Lumberjack\Support\RecordingRules::class);
        app()->forgetInstance(Recorder::class);

        report(new InvalidArgumentException('nope'));
        app(Recorder::class)->flush();

        expect($this->sentEvents('exception'))->toBe([]);
    });
});

describe('requests and queries', function () {
    it('records the request with its route and the queries it ran under one trace', function () {
        Route::get('/orders/{order}', fn () => DB::select('select 1 as one') ? 'ok' : 'no');

        $this->get('/orders/42')->assertOk();
        app()->terminate();

        $request = $this->sentEvents('request')[0];
        $query = $this->sentEvents('query')[0];

        expect($request)->toMatchArray(['method' => 'GET', 'route' => '/orders/{order}', 'status' => 200])
            ->and($request['url'])->toEndWith('/orders/42')
            ->and($query['sql'])->toBe('select 1 as one')
            ->and($query['trace_id'])->toBe($request['trace_id']);
    });

    it('records nothing for ignored routes', function () {
        Route::get('/up', fn () => DB::select('select 1') ? 'ok' : 'no');

        $this->get('/up')->assertOk();
        app()->terminate();

        expect($this->sentEvents('request'))->toBe([])->and($this->sentEvents('query'))->toBe([]);
    });

    it('ignores queries outside a request or job, like a worker polling for jobs', function () {
        DB::select('select 1');
        app()->terminate();

        expect($this->sentEvents('query'))->toBe([]);
    });
});

describe('jobs', function () {
    it('gives each job its own trace and flushes when it finishes', function () {
        $job = new TestJob();

        event(new JobProcessing('redis', $job));
        DB::select('select 2');
        event(new JobProcessed('redis', $job));

        $events = $this->sentEvents();
        expect(array_column($events, 'type'))->toBe(['job', 'query', 'job'])
            ->and(array_unique(array_column($events, 'trace_id')))->toHaveCount(1)
            ->and($events[2])->toMatchArray(['status' => 'processed', 'name' => 'App\\Jobs\\SendInvoice', 'queue' => 'emails', 'attempt' => 1, 'max_tries' => 3, 'timeout' => 60])
            ->and($events[2]['duration_ms'])->toBeFloat();
    });

    it('sends a failed job exception once, inside the job event', function () {
        $job = new TestJob();
        $exception = new RuntimeException('SMTP down');

        event(new JobProcessing('redis', $job));
        event(new JobFailed('redis', $job, $exception));
        report($exception);
        app()->terminate();

        $failed = $this->sentEvents('job')[1];
        expect($failed['status'])->toBe('failed')
            ->and($failed['exception']['message'])->toBe('SMTP down')
            ->and($this->sentEvents('exception'))->toBe([]);
    });
});

describe('logs', function () {
    it('sends log lines to the named Lumberjack channel', function () {
        config()->set('logging.channels.payments', ['driver' => 'lumberjack', 'channel' => 'payments']);

        Log::channel('payments')->warning('Refund took {seconds}s', ['seconds' => 12]);
        app()->terminate();

        expect($this->sentEvents('log')[0])->toMatchArray([
            'channel' => 'payments',
            'level' => 'warning',
            'message' => 'Refund took {seconds}s',
            'context' => ['seconds' => 12],
        ]);
    });

    it('drops the log line Laravel writes for an exception that was already reported', function () {
        config()->set('logging.channels.lumberjack', ['driver' => 'lumberjack']);
        config()->set('logging.default', 'lumberjack');

        report(new RuntimeException('once'));
        app()->terminate();

        expect($this->sentEvents('exception'))->toHaveCount(1)->and($this->sentEvents('log'))->toBe([]);
    });
});

describe('the @lumberjack directive', function () {
    it('renders the browser script with key, environment, release and the trace id', function () {
        $html = Blade::render('@lumberjack');
        $trace = app(Recorder::class)->traceId();

        expect($html)->toContain('src="https://lumberjack.test/sdk/v1.js"')
            ->toContain('data-key="pk_123"')
            ->toContain('data-environment="testing"')
            ->toContain('data-release="abc123"')
            ->toContain('data-trace="'.$trace.'"')
            ->not->toContain('crossorigin');
    });

    it('lets the script announce itself in the console only when asked to', function () {
        config()->set('lumberjack.announce', false);
        app()->forgetInstance(ExeQue\Lumberjack\BrowserScript::class);
        expect(Blade::render('@lumberjack'))->not->toContain('data-announce');

        config()->set('lumberjack.announce', true);
        app()->forgetInstance(ExeQue\Lumberjack\BrowserScript::class);
        expect(Blade::render('@lumberjack'))->toContain('data-announce="true"');
    });

    it('renders nothing without a public key', function () {
        config()->set('lumberjack.public_key', null);
        app()->forgetInstance(ExeQue\Lumberjack\BrowserScript::class);

        expect(Blade::render('@lumberjack'))->toBe('');
    });
});

describe('sampling', function () {
    it('records every job run, and the queries of only the sampled ones', function () {
        $this->withConfig(['lumberjack.jobs.sample_rate' => 0.0, 'lumberjack.queries.always_slower_than_ms' => 0]);
        $job = new TestJob();

        event(new JobProcessing('redis', $job));
        DB::select('select 2');
        event(new JobProcessed('redis', $job));

        expect(array_column($this->sentEvents(), 'type'))->toBe(['job', 'job']);
    });

    it('keeps a slow query from a run that was not sampled, and not a fast one', function () {
        $this->withConfig(['lumberjack.jobs.sample_rate' => 0.0, 'lumberjack.queries.always_slower_than_ms' => 500]);
        $job = new TestJob();
        $connection = DB::connection();

        event(new JobProcessing('redis', $job));
        event(new QueryExecuted('select fast', [], 3.0, $connection));
        event(new QueryExecuted('select slow', [], 900.0, $connection));
        event(new JobProcessed('redis', $job));

        expect(array_column($this->sentEvents('query'), 'sql'))->toBe(['select slow']);
    });

    it('keeps every query of a slow job run that was not sampled', function () {
        $this->withConfig(['lumberjack.jobs.sample_rate' => 0.0, 'lumberjack.jobs.always_slower_than_ms' => 0.0001]);
        $job = new TestJob();

        event(new JobProcessing('redis', $job));
        DB::select('select 2');
        usleep(1000);
        event(new JobProcessed('redis', $job));

        expect(array_column($this->sentEvents(), 'type'))->toBe(['job', 'query', 'job']);
    });

    it('keeps the queries of a failed job run that was not sampled', function () {
        $this->withConfig(['lumberjack.jobs.sample_rate' => 0.0, 'lumberjack.jobs.always_slower_than_ms' => 0, 'lumberjack.queries.always_slower_than_ms' => 0]);
        $job = new TestJob();

        event(new JobProcessing('redis', $job));
        DB::select('select 2');
        event(new JobFailed('redis', $job, new RuntimeException('SMTP down')));
        app()->terminate();

        expect(array_column($this->sentEvents('query'), 'sql'))->toBe(['select 2']);
    });

    it('records a request that was not sampled only when it was slow', function () {
        $this->withConfig(['lumberjack.requests.sample_rate' => 0.0, 'lumberjack.requests.always_slower_than_ms' => 600000, 'lumberjack.queries.always_slower_than_ms' => 0]);
        Route::get('/orders', fn () => DB::select('select 1 as one') ? 'ok' : 'no');
        $this->get('/orders')->assertOk();
        app()->terminate();
        expect($this->sentEvents('request'))->toBe([])->and($this->sentEvents('query'))->toBe([]);

        $this->withConfig(['lumberjack.requests.sample_rate' => 0.0, 'lumberjack.requests.always_slower_than_ms' => 0.0001]);
        Route::get('/orders', fn () => DB::select('select 1 as one') ? 'ok' : 'no');
        $this->get('/orders')->assertOk();
        app()->terminate();
        expect($this->sentEvents('request'))->toHaveCount(1)->and($this->sentEvents('query'))->toHaveCount(1);
    });

    it('leaves a slow query outside any run alone, like a worker waiting for jobs', function () {
        $this->withConfig(['lumberjack.queries.always_slower_than_ms' => 500]);

        event(new QueryExecuted('select slow', [], 900.0, DB::connection()));
        app()->terminate();

        expect($this->sentEvents('query'))->toBe([]);
    });
});
