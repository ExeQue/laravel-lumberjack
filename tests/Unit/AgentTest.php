<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Agent\Agent;
use ExeQue\Lumberjack\Agent\Sender;
use ExeQue\Lumberjack\Agent\Spool;
use ExeQue\Lumberjack\AgentClient;
use ExeQue\Lumberjack\Support\ProjectFingerprint;
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

it('sends a heartbeat with host and what it is', function () {
    Http::fake();
    ($this->makeAgent)()->tick(microtime(true));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/ingest/heartbeat') && $request['host'] === 'web-1'
        && $request['client']['sdk']['name'] === 'lumberjack.php.laravel'
        && $request['client']['runtime'] === ['name' => 'php', 'version' => PHP_VERSION]
        && $request['version'] === $request['client']['sdk']['version']
        && ! isset($request['name']));
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

it('turns away data from an app of another project, and warns about it once', function () {
    Http::fake(['*/api/ingest' => Http::response(['accepted' => 1], 202), '*/heartbeat' => Http::response(['ok' => true])]);
    $warnings = [];
    $own = ProjectFingerprint::of('secret-token');
    $agent = new Agent(new Sender(app(Factory::class), 'https://lumberjack.test', 'secret-token'), $this->spool, 5, 'web-1', null, $own, function (string $message) use (&$warnings): void {
        $warnings[] = $message;
    });
    $line = fn (?string $project, string $message): string => (string) json_encode(['v' => 1, 'sdk' => 'test', 'environment' => 'production', 'host' => 'web-1', 'project' => $project, 'events' => [['type' => 'log', 'message' => $message]]]);

    $agent->receive($line($own, 'mine'));
    $agent->receive($line(ProjectFingerprint::of('other-token'), 'theirs'));
    $agent->receive($line(ProjectFingerprint::of('other-token'), 'theirs again'));
    $agent->receive($line(null, 'unmarked, from an older SDK'));
    $agent->flush(microtime(true));

    $sent = collect(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/api/ingest')))
        ->flatMap(fn (array $pair) => array_column(json_decode((string) gzdecode($pair[0]->body()), true)['events'], 'message'));

    expect($sent->all())->toEqualCanonicalizing(['mine', 'unmarked, from an older SDK'])
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain(ProjectFingerprint::of('other-token'));
});

it('never sends the mark on to Lumberjack', function () {
    Http::fake(['*/api/ingest' => Http::response(['accepted' => 1], 202)]);
    $agent = new Agent(new Sender(app(Factory::class), 'https://lumberjack.test', 'secret-token'), $this->spool, 5, 'web-1', null, ProjectFingerprint::of('secret-token'));

    $agent->receive((string) json_encode(['v' => 1, 'project' => ProjectFingerprint::of('secret-token'), 'events' => [['type' => 'log', 'message' => 'x']]]));
    $agent->flush(microtime(true));

    [[$request]] = Http::recorded();
    expect(json_decode((string) gzdecode($request->body()), true))->not->toHaveKey('project');
});

it('stops trying a missing agent for a while, and reports what it dropped once the agent is back', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);
    $client = new AgentClient('127.0.0.1', $port, breakSeconds: 0.2);

    expect($client->send(['v' => 1, 'events' => []]))->toBeFalse();

    // The agent comes back, but the breaker is still open: nothing connects.
    $server = stream_socket_server("tcp://127.0.0.1:{$port}");
    expect($client->send(['v' => 1, 'events' => []]))->toBeFalse()
        ->and(@stream_socket_accept($server, 0.05))->toBeFalse();

    usleep(250_000);
    expect($client->send(['v' => 1, 'events' => [['type' => 'query']]]))->toBeTrue();

    $events = json_decode((string) fgets(stream_socket_accept($server, 1)), true)['events'];
    expect($events)->toHaveCount(2)
        ->and($events[1])->toMatchArray(['type' => 'log', 'level' => 'warning', 'message' => 'Lumberjack dropped 2 batches while the agent could not be reached.']);
});

it('drains the spool a little per tick, so it goes back to accepting connections', function () {
    $spool = new Spool($this->spoolPath, 10_000_000);

    foreach (range(1, 10) as $i) {
        $spool->push((string) gzencode('{"events":[]}'));
    }

    Http::fake(function () {
        usleep(100_000);

        return Http::response(['accepted' => 1], 202);
    });
    $agent = new Agent(new Sender(app(Factory::class), 'https://lumberjack.test', 'secret-token'), $spool, 5, 'web-1');

    $start = microtime(true);
    $agent->tick(microtime(true));

    expect(microtime(true) - $start)->toBeLessThan(0.6)
        ->and($spool->count())->toBeGreaterThanOrEqual(5);
});

describe('the agent key', function () {
    beforeEach(function () {
        Http::fake(['*/api/ingest' => Http::response(['accepted' => 1], 202), '*/heartbeat' => Http::response(['ok' => true])]);
        $this->warnings = [];
        $this->key = ProjectFingerprint::agentKey('secret-token');
        $this->keyed = new Agent(new Sender(app(Factory::class), 'https://lumberjack.test', 'secret-token'), $this->spool, 5, 'web-1', null, ProjectFingerprint::of('secret-token'), function (string $message): void {
            $this->warnings[] = $message;
        }, key: $this->key);
    });

    it('turns away data and commands without it, and warns once', function () {
        $this->keyed->receive((string) json_encode(['events' => [['type' => 'exception', 'message' => 'forged']]]));
        $this->keyed->receive((string) json_encode(['key' => 'guess', 'events' => [['type' => 'exception']]]));
        $this->keyed->receive((string) json_encode(['control' => 'terminate']));
        $this->keyed->flush(microtime(true));

        expect($this->keyed->stopRequested())->toBeFalse()
            ->and($this->warnings)->toHaveCount(1)->and($this->warnings[0])->toContain('without the agent key');
        Http::assertNothingSent();
    });

    it('takes data and commands with it', function () {
        $this->keyed->receive((string) json_encode(['key' => $this->key, 'events' => [['type' => 'log']]]));
        $this->keyed->flush(microtime(true));
        $this->keyed->receive((string) json_encode(['key' => $this->key, 'control' => 'terminate']));

        expect($this->keyed->stopRequested())->toBeTrue();
        Http::assertSentCount(1);
    });

    it('is put on every line by the app, derived from the token, never the token itself', function () {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

        (new AgentClient('127.0.0.1', $port, key: $this->key))->send(['control' => 'terminate']);

        $line = (string) fgets(stream_socket_accept($server, 1));
        expect(json_decode($line, true))->toBe(['control' => 'terminate', 'key' => $this->key])
            ->and($line)->not->toContain('secret-token')
            ->and(ProjectFingerprint::agentKey('secret-token', 'explicit'))->toBe('explicit');
    });

    it('splits a batch that is too long for one line', function () {
        $client = new class ('127.0.0.1', 1) extends AgentClient {
            /** @var list<string> */
            public array $lines = [];

            protected function write(string $line): bool
            {
                $this->lines[] = $line;

                return true;
            }
        };
        $event = ['type' => 'log', 'message' => str_repeat('x', 10_000)];

        expect($client->send(['v' => 1, 'events' => array_fill(0, 1000, $event)]))->toBeTrue()
            ->and(count($client->lines))->toBeGreaterThan(1)
            ->and(max(array_map(strlen(...), $client->lines)))->toBeLessThanOrEqual(AgentClient::MAX_LINE)
            ->and(array_sum(array_map(fn (string $line): int => count(json_decode($line, true)['events']), $client->lines)))->toBe(1000);
    });
});

describe('the agent host', function () {
    it('sends to a host name', function () {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        $client = new AgentClient('localhost', $port);

        expect($client->send(['events' => []]))->toBeTrue()
            ->and($client->send(['events' => []]))->toBeTrue();
    });

    it('does not look up a name that failed again for a while', function () {
        $client = new AgentClient('lumberjack-agent.invalid', 2407);
        $client->send(['events' => []]);

        $start = microtime(true);
        expect($client->send(['events' => []]))->toBeFalse()
            ->and(microtime(true) - $start)->toBeLessThan(0.002);
    });
});

describe('the spool', function () {
    it('keeps batches readable by the agent\'s user only', function () {
        $this->spool->push('batch');

        expect(fileperms($this->spoolPath) & 0777)->toBe(0700)
            ->and(fileperms((string) $this->spool->oldest()) & 0777)->toBe(0600);
    });

    it('says once when it cannot write', function () {
        $warnings = [];
        $file = tempnam(sys_get_temp_dir(), 'lj');
        $spool = new Spool($file.'/spool', 10_000, function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });

        $spool->push('a');
        $spool->push('b');

        expect($warnings)->toHaveCount(1)->and($warnings[0])->toContain('Could not write to the spool');
        unlink($file);
    });
});

it('sends request counts in batches of their own, so a server that refuses them keeps the rest', function () {
    Http::fake(function (Request $request) {
        $events = json_decode((string) gzdecode($request->body()), true)['events'] ?? [];

        return in_array('request_counts', array_column($events, 'type'), true) ? Http::response([], 422) : Http::response(['accepted' => 1], 202);
    });
    $agent = ($this->makeAgent)();

    $agent->receive(($this->line)([['type' => 'request', 'route' => '/'], ['type' => 'request_counts', 'counts' => []]]));
    $agent->flush(microtime(true));

    $types = array_map(fn (array $pair): array => array_column(json_decode((string) gzdecode($pair[0]->body()), true)['events'], 'type'), Http::recorded()->all());
    expect($types)->toBe([['request'], ['request_counts']])
        ->and($this->spool->count())->toBe(0);
});
