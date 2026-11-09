<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Logging\LumberjackHandler;
use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Support\Safely;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Monolog\Level;
use Monolog\Logger;

describe('recording never breaks the app', function () {
    it('swallows what a wrapped listener throws', function () {
        $ran = false;
        $listener = Safely::wrap(function () use (&$ran): void {
            $ran = true;

            throw new RuntimeException('Recorder broke');
        });

        $listener('anything');

        expect($ran)->toBeTrue();
    });

    it('remembers at most MAX_PROBLEMS problems, so a long-running worker does not grow', function () {
        $log = ini_set('error_log', '/dev/null');

        foreach (range(1, Safely::MAX_PROBLEMS + 50) as $i) {
            Safely::problem("Lumberjack test problem {$i}");
        }

        ini_set('error_log', (string) $log);

        expect((new ReflectionProperty(Safely::class, 'problems'))->getValue())->toHaveCount(Safely::MAX_PROBLEMS);
    });

    it('keeps a query working when recording it fails', function () {
        $recorder = Mockery::mock(Recorder::class)->makePartial();
        $recorder->shouldReceive('record')->andThrow(new RuntimeException('Recorder broke'));
        Event::listen(QueryExecuted::class, Safely::wrap(fn (QueryExecuted $e) => $recorder->record(['type' => 'query'])));

        expect(DB::select('select 1 as one')[0]->one)->toBe(1);
    });

    it('keeps logging working when the Lumberjack log handler fails', function () {
        $recorder = Mockery::mock(Recorder::class);
        $recorder->shouldReceive('record')->andThrow(new RuntimeException('Recorder broke'));
        $logger = new Logger('test', [new LumberjackHandler($recorder, null, Level::Debug)]);

        $logger->info('Still logs');

        expect(true)->toBeTrue();
    });
});

describe('while the agent is away', function () {
    it('keeps what the app sends in the configured folder', function () {
        $path = sys_get_temp_dir().'/lumberjack-app-spool-'.bin2hex(random_bytes(4));
        $this->withConfig(['lumberjack.agent.port' => 1, 'lumberjack.agent.app_spool_path' => $path]);
        app()->forgetInstance(ExeQue\LumberjackAgent\AgentClient::class);
        app()->offsetUnset(ExeQue\LumberjackAgent\AgentClient::class);
        (new ExeQue\Lumberjack\LumberjackServiceProvider(app()))->register();

        app(ExeQue\LumberjackAgent\AgentClient::class)->send(['v' => 1, 'events' => [['type' => 'log']]]);

        expect(glob($path.'/*.json.gz'))->toHaveCount(1);

        array_map('unlink', glob($path.'/*') ?: []);
        rmdir($path);
    });
});
