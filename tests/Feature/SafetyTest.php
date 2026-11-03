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
