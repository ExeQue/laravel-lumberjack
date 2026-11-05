<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Support\QueueSizes;
use ExeQue\LumberjackAgent\Agent;
use ExeQue\LumberjackAgent\Sender;
use ExeQue\LumberjackAgent\Spool;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['queue.connections.database' => ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]]);
    Schema::create('jobs', function ($table) {
        $table->id();
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

it('measures the size and the oldest pending job of each named queue', function () {
    $now = time();
    DB::table('jobs')->insert([
        ['queue' => 'emails', 'payload' => '{}', 'attempts' => 0, 'available_at' => $now - 120, 'created_at' => $now - 120],
        ['queue' => 'emails', 'payload' => '{}', 'attempts' => 0, 'available_at' => $now - 10, 'created_at' => $now - 10],
    ]);

    $samples = (new QueueSizes(app(QueueFactory::class), ['database:emails', 'database:'], 'database'))->measure();

    expect($samples)->toHaveCount(2)
        ->and($samples[0])->toMatchArray(['connection' => 'database', 'queue' => 'emails', 'size' => 2])
        ->and($samples[0]['oldest_age_s'])->toBeGreaterThanOrEqual(120)
        ->and($samples[1])->toBe(['connection' => 'database', 'queue' => 'default', 'size' => 0, 'oldest_age_s' => null]);
});

it('measures the default queue when none are named, and skips a queue it cannot reach', function () {
    config(['queue.default' => 'database']);

    expect((new QueueSizes(app(QueueFactory::class)))->measure())->toBe([['connection' => 'database', 'queue' => 'default', 'size' => 0, 'oldest_age_s' => null]])
        ->and((new QueueSizes(app(QueueFactory::class), ['nowhere:default']))->measure())->toBe([]);
});

it('sends queue sizes with the heartbeat, and still beats when measuring fails', function () {
    Http::fake();
    $spool = new Spool(sys_get_temp_dir().'/lumberjack-spool-'.bin2hex(random_bytes(4)), 10_000);
    $sender = new Sender('https://lumberjack.test', 'secret-token', http: new GuzzleHttp\Client(['handler' => Http::buildHandlerStack()]));

    (new Agent($sender, $spool, 5, 'web-1', status: fn (): array => ['queues' => [['connection' => 'redis', 'queue' => 'default', 'size' => 3, 'oldest_age_s' => 5]]]))->tick(microtime(true));
    (new Agent($sender, $spool, 5, 'web-2', status: fn () => throw new RuntimeException('redis is down')))->tick(microtime(true));

    Http::assertSent(fn (Request $request) => $request['host'] === 'web-1' && $request['queues'][0]['size'] === 3);
    Http::assertSent(fn (Request $request) => $request['host'] === 'web-2' && ! isset($request['queues']));
});
