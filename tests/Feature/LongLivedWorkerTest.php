<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;

require_once __DIR__.'/../Support/OctaneEvents.php';

describe('a worker that serves many requests', function () {
    it('gives every request its own trace', function () {
        Route::get('/a', fn () => DB::select('select 1') ? 'a' : '');
        Route::get('/b', fn () => DB::select('select 2') ? 'b' : '');

        $this->get('/a')->assertOk();
        $this->get('/b')->assertOk();
        $this->get('/a')->assertOk();
        app()->terminate();

        $requests = $this->sentEvents('request');
        $queries = $this->sentEvents('query');

        expect(array_unique(array_column($requests, 'trace_id')))->toHaveCount(3)
            ->and(array_column($queries, 'trace_id'))->toBe(array_column($requests, 'trace_id'));
    });

    it('starts a new trace when Octane hands over the next request, and sends what the last one left', function () {
        config()->set('logging.channels.lumberjack', ['driver' => 'lumberjack']);
        $first = Request::create('/one');
        $second = Request::create('/two');

        event(new RequestReceived(app(), app(), $first));
        Log::channel('lumberjack')->info('first');
        event(new RequestReceived(app(), app(), $second));
        Log::channel('lumberjack')->info('second');
        event(new RequestTerminated(app(), app(), $second));

        $logs = $this->sentEvents('log');
        expect($logs)->toHaveCount(2)
            ->and($logs[0]['trace_id'])->not->toBe($logs[1]['trace_id']);
    });

    it('sends the request being served with an exception, though the worker runs in the console', function () {
        expect(app()->runningInConsole())->toBeTrue();

        event(new RequestReceived(app(), app(), Request::create('/checkout', 'POST')));
        report(new RuntimeException('boom'));
        event(new RequestTerminated(app(), app(), Request::create('/checkout', 'POST')));
        report(new RuntimeException('after'));
        app()->terminate();

        $exceptions = $this->sentEvents('exception');
        expect($exceptions[0]['context']['request'])->toMatchArray(['method' => 'POST'])
            ->and($exceptions[0]['context']['request']['url'])->toEndWith('/checkout')
            ->and($exceptions[1]['context'])->not->toHaveKey('request');
    });
});
