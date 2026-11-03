<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(Illuminate\Foundation\Testing\WithConsoleEvents::class);

beforeEach(function () {
    Artisan::command('reports:send {period} {--force} {--limit=} {--dry-run}', function (): int {
        DB::select('select 1');

        return $this->option('limit') === '0' ? 1 : 0;
    });
    Artisan::command('reports:explode', fn () => throw new RuntimeException('Report server down'));
});

describe('artisan commands', function () {
    it('records a run with the arguments and options it was given, its queries and exit code', function () {
        Artisan::call('reports:send', ['period' => 'daily', '--force' => true, '--limit' => '50']);
        app()->terminate();

        [$started, $query, $finished] = $this->sentEvents();

        expect($started)->toMatchArray(['type' => 'command', 'status' => 'started', 'name' => 'reports:send'])
            ->and($query['type'])->toBe('query')
            ->and($query['trace_id'])->toBe($started['trace_id'])
            ->and($finished)->toMatchArray([
                'status' => 'finished',
                'uuid' => $started['uuid'],
                'exit_code' => 0,
                'exception' => false,
                'arguments' => ['period' => 'daily'],
                'options' => ['force' => true, 'limit' => '50'],
            ]);
    });

    it('sends the exit code of a command that fails', function () {
        Artisan::call('reports:send', ['period' => 'daily', '--limit' => '0']);
        app()->terminate();

        expect($this->sentEvents('command')[1])->toMatchArray(['exit_code' => 1, 'exception' => false]);
    });

    it('ends a command that died on an exception when the app terminates, and says the exception came with it', function () {
        try {
            Artisan::call('reports:explode');
        } catch (RuntimeException $e) {
            report($e);
        }

        app()->terminate();

        expect($this->sentEvents('command')[1])->toMatchArray(['status' => 'finished', 'exit_code' => 1, 'exception' => true])
            ->and($this->sentEvents('exception'))->toHaveCount(1);
    });

    it('leaves out ignored commands', function () {
        $this->withConfig(['lumberjack.commands.ignore' => ['reports:*']]);
        $this->setUpWithConsoleEvents();
        Artisan::command('reports:send', fn () => 0);
        Artisan::command('invoices:send', fn () => 0);

        Artisan::call('reports:send');
        Artisan::call('invoices:send');
        app()->terminate();

        expect(array_column($this->sentEvents('command'), 'name'))->toBe(['invoices:send', 'invoices:send']);
    });

    it('records every command run, and the queries of only the sampled ones', function () {
        $this->withConfig(['lumberjack.commands.sample_rate' => 0.0, 'lumberjack.commands.always_slower_than_ms' => 0, 'lumberjack.queries.always_slower_than_ms' => 0]);
        $this->setUpWithConsoleEvents();
        Artisan::command('reports:send {period} {--force} {--limit=} {--dry-run}', function (): int {
            DB::select('select 1');

            return $this->option('limit') === '0' ? 1 : 0;
        });

        Artisan::call('reports:send', ['period' => 'daily']);
        app()->terminate();

        expect(array_column($this->sentEvents(), 'type'))->toBe(['command', 'command']);
    });

    it('keeps the queries of a failed command run that was not sampled', function () {
        $this->withConfig(['lumberjack.commands.sample_rate' => 0.0, 'lumberjack.commands.always_slower_than_ms' => 0, 'lumberjack.queries.always_slower_than_ms' => 0]);
        $this->setUpWithConsoleEvents();
        Artisan::command('reports:send {period} {--force} {--limit=} {--dry-run}', function (): int {
            DB::select('select 1');

            return $this->option('limit') === '0' ? 1 : 0;
        });

        Artisan::call('reports:send', ['period' => 'daily', '--limit' => '0']);
        app()->terminate();

        expect($this->sentEvents('query'))->toHaveCount(1);
    });
});

describe('a command called from inside a request', function () {
    it('is a part of that request, on its trace, and the request is still recorded', function () {
        Illuminate\Support\Facades\Route::get('/report', function () {
            DB::select('select 1 as before_call');
            Artisan::call('reports:send', ['period' => 'daily']);
            DB::select('select 2 as after_call');

            return 'ok';
        });

        $this->get('/report')->assertOk();
        app()->terminate();

        $events = $this->sentEvents();
        $request = $this->sentEvents('request');

        expect($request)->toHaveCount(1)
            ->and(array_unique(array_column($events, 'trace_id')))->toBe([$request[0]['trace_id']])
            ->and(array_column($this->sentEvents('query'), 'sql'))->toBe(['select 1 as before_call', 'select 1', 'select 2 as after_call'])
            ->and(array_column($this->sentEvents('command'), 'status'))->toBe(['started', 'finished']);
    });
});
