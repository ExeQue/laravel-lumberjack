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
        Artisan::command('reports:send', fn () => 0);

        Artisan::call('reports:send');
        app()->terminate();

        expect($this->sentEvents('command'))->toBe([]);
    });
});
