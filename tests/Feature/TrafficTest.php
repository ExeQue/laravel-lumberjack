<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Recorder;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\TestJob;

describe('true traffic despite sampling', function () {
    it('counts every request, sampled or not, by method, route and status, and resets on flush', function () {
        $this->withConfig(['lumberjack.requests.sample_rate' => 0.0, 'lumberjack.requests.always_slower_than_ms' => 0]);
        Route::get('/orders/{order}', fn () => 'ok');
        Route::get('/missing', fn () => abort(404));
        Route::get('/up', fn () => 'ok');

        $this->get('/orders/1');
        $this->get('/orders/2');
        $this->get('/missing');
        $this->get('/up'); // ignored by the rules: not counted
        app(Recorder::class)->flush();

        // Each test request terminates, and so flushes, on its own: add the events up.
        $events = $this->sentEvents('request_counts');
        $counts = $events[0];
        $rows = collect($events)->flatMap(fn (array $e): array => $e['counts'])
            ->groupBy(fn (array $row): string => $row['route'].' '.$row['status'])
            ->map(fn ($rows): array => [...$rows[0], 'count' => $rows->sum('count'), 'duration_ms_sum' => (float) $rows->sum('duration_ms_sum')]);

        expect($this->sentEvents('request'))->toBe([])
            ->and($rows->keys()->sort()->values()->all())->toBe(['/missing 404', '/orders/{order} 200'])
            ->and($rows['/orders/{order} 200'])->toMatchArray(['method' => 'GET', 'count' => 2])
            ->and($rows['/orders/{order} 200']['duration_ms_sum'])->toBeFloat()
            ->and($counts['from'])->toBeLessThanOrEqual($counts['to']);

        $this->agent->payloads = [];
        app(Recorder::class)->flush();
        expect($this->agent->payloads)->toBe([]);
    });

    it('puts the rows past the cap in one other row', function () {
        $recorder = app(Recorder::class);

        foreach (range(1, Recorder::MAX_COUNTED + 3) as $i) {
            $recorder->countRequest('GET', "/r{$i}", 200, 1.0);
        }
        $recorder->flush();

        $counts = $this->sentEvents('request_counts')[0]['counts'];
        expect($counts)->toHaveCount(Recorder::MAX_COUNTED + 1)
            ->and(end($counts))->toMatchArray(['method' => '*', 'route' => 'other', 'status' => 0, 'count' => 3]);
    });

    it('weighs request, job and command runs and their queries by the rate that applied', function () {
        $this->withConfig(['lumberjack.requests.sample_rate' => 1.0, 'lumberjack.jobs.sample_rate' => 0.0, 'lumberjack.jobs.always_slower_than_ms' => 0]);
        Route::get('/a', fn () => DB::select('select 1') ? 'ok' : '');

        $this->get('/a');
        $job = new TestJob();
        event(new JobProcessing('redis', $job));
        DB::select('select 2');
        event(new JobProcessed('redis', $job));
        app()->terminate();

        expect($this->sentEvents('request')[0])->toMatchArray(['sample_rate' => 1.0, 'sampled' => true])
            ->and($this->sentEvents('query')[0])->toMatchArray(['sample_rate' => 1.0, 'sampled' => true])
            ->and($this->sentEvents('job')[1])->toMatchArray(['status' => 'processed', 'sample_rate' => 0.0, 'sampled' => false]);
    });

    it('marks a run kept only because it was slow as not sampled, with its queries', function () {
        $this->withConfig(['lumberjack.requests.sample_rate' => 0.0, 'lumberjack.requests.always_slower_than_ms' => 0.001]);
        Route::get('/slow', fn () => DB::select('select 1') ? 'ok' : '');

        $this->get('/slow');
        app()->terminate();

        expect($this->sentEvents('request')[0])->toMatchArray(['sample_rate' => 0.0, 'sampled' => false])
            ->and($this->sentEvents('query')[0])->toMatchArray(['sample_rate' => 0.0, 'sampled' => false]);
    });
});
