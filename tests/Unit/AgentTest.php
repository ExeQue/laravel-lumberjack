<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Agent\Agent;
use ExeQue\Lumberjack\Agent\Sender;
use ExeQue\Lumberjack\Agent\Spool;
use ExeQue\Lumberjack\AgentClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->spoolPath = sys_get_temp_dir().'/lumberjack-spool-'.bin2hex(random_bytes(4));
    $this->spool = new Spool($this->spoolPath, 10_000);
    $this->makeAgent = fn (): Agent => new Agent(new Sender(app(Factory::class), 'https://lumberjack.test', 'secret-token'), $this->spool, 5, 'web-1');
    $this->line = fn (array $events, string $env = 'production'): string => (string) json_encode(['v' => 1, 'sdk' => 'test', 'environment' => $env, 'release' => 'r1', 'host' => 'web-1', 'events' => $events]);
});

afterEach(fn () => File::deleteDirectory($this->spoolPath));

it('sends gzipped batches with the bearer token, one per envelope', function () {
    Http::fake(['*/api/ingest' => Http::response(['accepted' => 1], 202), '*/heartbeat' => Http::response(['ok' => true])]);
    $agent = ($this->makeAgent)();

    $agent->receive(($this->line)([['type' => 'query', 'sql' => 'select 1']]));
    $agent->receive(($this->line)([['type' => 'query', 'sql' => 'select 2']]));
    $agent->receive(($this->line)([['type' => 'log', 'message' => 'hi']], 'staging'));
    $agent->flush(microtime(true));

    $batches = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/api/ingest'));
    expect($batches)->toHaveCount(2);

    [$request] = $batches[0];
    $body = json_decode((string) gzdecode($request->body()), true);
    expect($request->hasHeader('Authorization', 'Bearer secret-token'))->toBeTrue()
        ->and($request->hasHeader('Content-Encoding', 'gzip'))->toBeTrue()
        ->and($body['environment'])->toBe('production')
        ->and(array_column($body['events'], 'sql'))->toBe(['select 1', 'select 2']);
});

it('spools a batch when Lumberjack is down and sends it once it is back', function () {
    Http::fakeSequence('*/api/ingest')->push('down', 503)->push(['accepted' => 1], 202);
    Http::fake(['*/heartbeat' => Http::response(['ok' => true])]);
    $agent = ($this->makeAgent)();
    $now = microtime(true);

    $agent->receive(($this->line)([['type' => 'log', 'message' => 'keep me']]));
    $agent->flush($now);

    expect($this->spool->count())->toBe(1)->and($agent->backingOff($now + 1))->toBeTrue();

    $agent->tick($now + 6);

    expect($this->spool->count())->toBe(0);
});

it('drops a batch the server rejects instead of retrying it forever', function () {
    Http::fake(['*/api/ingest' => Http::response(['message' => 'invalid'], 422)]);
    $agent = ($this->makeAgent)();

    $agent->receive(($this->line)([['type' => 'nonsense']]));
    $agent->flush(microtime(true));

    expect($this->spool->count())->toBe(0)->and($agent->backingOff(microtime(true)))->toBeFalse();
});

it('stops when asked to terminate, without treating the message as data', function () {
    Http::fake();
    $agent = ($this->makeAgent)();

    $agent->receive((string) json_encode(['control' => 'terminate']));
    $agent->flush(microtime(true));

    expect($agent->stopRequested())->toBeTrue();
    Http::assertNothingSent();
});

it('drops the oldest spooled batch when the spool is full', function () {
    $spool = new Spool($this->spoolPath, 2500);

    $spool->push(str_repeat('a', 1000));
    $spool->push(str_repeat('b', 1000));
    $spool->push(str_repeat('c', 1000));

    expect($spool->count())->toBe(2)->and($spool->read((string) $spool->oldest()))->toStartWith('b');
});

it('sends a heartbeat with host and version', function () {
    Http::fake();
    ($this->makeAgent)()->tick(microtime(true));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/ingest/heartbeat') && $request['host'] === 'web-1' && $request['version'] !== null && ! isset($request['name']));
});

it('sends its name with the heartbeat when it has one', function () {
    Http::fake();
    (new Agent(new Sender(app(Factory::class), 'https://lumberjack.test', 'secret-token'), $this->spool, 5, 'web-1', 'queue'))->tick(microtime(true));

    Http::assertSent(fn (Request $request) => $request['name'] === 'queue');
});

it('hands a payload to a listening agent over TCP as one JSON line', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    expect((new AgentClient('127.0.0.1', $port))->send(['v' => 1, 'events' => [['type' => 'log']]]))->toBeTrue();

    $client = stream_socket_accept($server, 1);
    expect(json_decode((string) fgets($client), true))->toBe(['v' => 1, 'events' => [['type' => 'log']]]);
});

it('gives up quietly when no agent is listening', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    $start = microtime(true);

    expect((new AgentClient('127.0.0.1', $port))->send(['v' => 1, 'events' => []]))->toBeFalse()
        ->and(microtime(true) - $start)->toBeLessThan(0.5);
});
