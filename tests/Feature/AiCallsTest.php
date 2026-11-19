<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Activity\Actor;
use ExeQue\Lumberjack\Ai\AiCall;
use ExeQue\Lumberjack\Ai\AiCallRecorder;
use ExeQue\Lumberjack\Ai\AiContext;
use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Support\ExceptionContext;
use ExeQue\Lumberjack\Testing\EventType;
use ExeQue\Lumberjack\Testing\RecordedAiCall;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Auth;
use Laravel\Octane\Events\RequestReceived;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\Activity\Customer;
use Tests\Support\TestJob;

require_once __DIR__.'/../Support/OctaneEvents.php';

covers(AiCall::class, AiCallRecorder::class, AiContext::class, RecordedAiCall::class);

/**
 * A stream object with an invocation id, as Laravel AI's streamable response is.
 */
function boundStream(string $invocationId): IteratorAggregate
{
    return new class ($invocationId) implements IteratorAggregate {
        public function __construct(public string $invocationId)
        {
        }

        public function getIterator(): Iterator
        {
            yield 1;
        }
    };
}

beforeEach(function () {
    $this->tenant = new Actor('tenant', 42);
    $this->calls = function (): array {
        app(Recorder::class)->flush();

        return $this->sentEvents('ai_call');
    };
});

describe('Lumberjack::aiUsage()', function () {
    it('records the call with its tokens, as given', function () {
        Lumberjack::aiUsage(provider: 'anthropic', model: 'claude-sonnet-4-5', input: 1200, output: 300, cacheRead: 800, cacheWrite: 0, operation: 'stream', durationMs: 1834.21234);

        expect(($this->calls)()[0])->toMatchArray([
            'type' => 'ai_call',
            'source' => 'manual',
            'provider' => 'anthropic',
            'driver' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'operation' => 'stream',
            'tokens' => ['input' => 1200, 'output' => 300, 'cache_read' => 800, 'cache_write' => 0, 'reasoning' => null],
            'duration_ms' => 1834.212,
            'status' => 'ok',
            'error' => null,
            'key' => null,
            'actor' => null,
        ]);
    });

    it('sends only the fingerprint of the key, never the key', function () {
        Lumberjack::aiUsage(provider: 'openai', model: 'gpt-5', input: 10, output: 2, key: 'sk-proj-very-secret-key');

        $sent = json_encode($this->agent->payloads);

        expect(($this->calls)()[0]['key']['sha256'])->toBe(hash('sha256', 'openai:sk-proj-very-secret-key'))
            ->and($sent)->not->toContain('sk-proj-very-secret-key');
    });

    it('fingerprints the key under the driver in lowercase, as the server looks it up', function () {
        Lumberjack::aiUsage(provider: 'OpenAI', model: 'gpt-5', key: 'sk-1');

        expect(($this->calls)()[0])->toMatchArray(['driver' => 'openai'])
            ->and(($this->calls)()[0]['key']['sha256'])->toBe(hash('sha256', 'openai:sk-1'))
            ->and(AiCall::fingerprint('Anthropic', 'sk-2'))->toBe(AiCall::fingerprint('anthropic', 'sk-2'));
    });

    it('marks a call with an error as failed', function () {
        Lumberjack::aiUsage(provider: 'openai', model: 'gpt-5', error: 'RateLimitedException');

        expect(($this->calls)()[0])->toMatchArray(['status' => 'error', 'error' => 'RateLimitedException']);
    });

    it('still records the call when the resolver throws, and says so', function () {
        Lumberjack::resolveAiKeyUsing(fn (): never => throw new RuntimeException('broken resolver'));

        Lumberjack::ai(fn () => Lumberjack::aiUsage(provider: 'openai', model: 'gpt-5'), label: 'scoped');

        $logs = collect(tap(app(Recorder::class))->flush() ? $this->sentEvents('log') : [])->pluck('message')->implode("\n");
        expect(($this->calls)())->toHaveCount(1)
            ->and(($this->calls)()[0]['key']['label'])->toBe('scoped')
            ->and($logs)->toContain('resolver failed: RuntimeException')
            ->and($logs)->not->toContain('broken resolver');
    });

    it('still records the call when what the resolver returns cannot be told', function () {
        $broken = new class () implements ExeQue\Lumberjack\Contracts\ActsAsLumberjackActor {
            public function toLumberjackActor(): Actor
            {
                throw new RuntimeException('no owner');
            }
        };
        Lumberjack::resolveAiKeyUsing(fn (): array => ['owner' => $broken]);

        Lumberjack::aiUsage(provider: 'openai', model: 'gpt-5');

        expect(($this->calls)())->toHaveCount(1);
    });

    it('records nothing when AI recording is turned off', function () {
        $this->withConfig(['lumberjack.ai.enabled' => false]);

        Lumberjack::aiUsage(provider: 'openai', model: 'gpt-5', input: 1);

        expect(($this->calls)())->toBe([]);
    });
});

describe('sampling', function () {
    it('sends an AI call of an unsampled run that ends without being kept, at full weight', function () {
        $recorder = app(Recorder::class);
        $recorder->startRun(sampled: false, rate: 0.1);

        Lumberjack::aiUsage(provider: 'openai', model: 'gpt-5', input: 10);
        $recorder->endRun(keep: false);

        $calls = ($this->calls)();
        expect($calls)->toHaveCount(1)
            ->and($calls[0])->not->toHaveKey('sample_rate')
            ->and($calls[0])->not->toHaveKey('sampled');
    });
});

describe('the actor and the key owner', function () {
    it('takes the logged in user of the request being served as the actor', function () {
        Auth::setUser((new User())->forceFill(['id' => 1337]));
        app(ExceptionContext::class)->serving(Request::create('/chat'));

        Lumberjack::aiUsage(provider: 'openai', model: 'gpt-5');

        expect(($this->calls)()[0]['actor'])->toBe(['type' => User::class, 'id' => '1337']);
    });

    it('takes no actor in a queue worker or command, where a logged in user may be left from the last job', function () {
        Auth::setUser((new User())->forceFill(['id' => 1337]));

        Lumberjack::aiUsage(provider: 'openai', model: 'gpt-5');

        expect(($this->calls)()[0]['actor'])->toBeNull();
    });

    it('stops taking the logged in user once a queued job starts in the worker', function () {
        Auth::setUser((new User())->forceFill(['id' => 1337]));
        app(ExceptionContext::class)->serving(Request::create('/chat'));

        event(new JobProcessing('redis', new TestJob()));
        Lumberjack::aiUsage(provider: 'openai', model: 'gpt-5');

        expect(($this->calls)()[0]['actor'])->toBeNull();
    });

    it('keeps stream scopes for a sync, deferred or background job, which runs inside the request', function (string $connection) {
        $context = app(AiContext::class);
        $context->run(fn () => boundStream('inv-sync'), label: 'bound');

        event(new JobProcessing($connection, new SyncJob(app(), '{"job":"x"}', $connection, 'default')));

        expect($context->current('inv-sync')['label'])->toBe('bound');
    })->with(['sync', 'deferred', 'background']);

    it('still runs the callback when who it is for cannot be told', function () {
        $broken = new class () implements ExeQue\Lumberjack\Contracts\ActsAsLumberjackActor {
            public function toLumberjackActor(): Actor
            {
                throw new RuntimeException('no actor');
            }
        };

        expect(Lumberjack::ai(fn () => 'ran', by: $broken))->toBe('ran');
    });

    it('takes the actor, owner and label of the Lumberjack::ai() scope, and returns what the callback returns', function () {
        $result = Lumberjack::ai(fn () => tap('done', fn () => Lumberjack::aiUsage(provider: 'openai', model: 'gpt-5', key: 'tenant-key')), by: Customer::make(17), owner: $this->tenant, label: 'byok');

        expect($result)->toBe('done')
            ->and(($this->calls)()[0])->toMatchArray([
                'actor' => ['type' => Customer::class, 'id' => '17'],
                'key' => ['sha256' => hash('sha256', 'openai:tenant-key'), 'label' => 'byok', 'owner' => ['type' => 'tenant', 'id' => '42']],
            ]);
    });

    it('lets an inner scope override only what it sets', function () {
        Lumberjack::ai(function () {
            Lumberjack::ai(fn () => Lumberjack::aiUsage(provider: 'openai', model: 'inner'), label: 'inner-label');
            Lumberjack::aiUsage(provider: 'openai', model: 'outer');
        }, by: Customer::make(17), owner: $this->tenant, label: 'outer-label');
        Lumberjack::aiUsage(provider: 'openai', model: 'outside');

        [$inner, $outer, $outside] = ($this->calls)();
        expect($inner['key'])->toBe(['sha256' => null, 'label' => 'inner-label', 'owner' => ['type' => 'tenant', 'id' => '42']])
            ->and($inner['actor'])->toBe(['type' => Customer::class, 'id' => '17'])
            ->and($outer['key']['label'])->toBe('outer-label')
            ->and($outside['key'])->toBeNull()
            ->and($outside['actor'])->toBeNull();
    });

    it('keeps the scope for a generator read after the callback returned, and only while it runs', function () {
        $seen = [];
        $stream = Lumberjack::ai(function () use (&$seen) {
            return (function () use (&$seen) {
                $seen[] = app(AiContext::class)->current()['label'];
                yield 1;
                $seen[] = app(AiContext::class)->current()['label'];
                yield 2;

                return 'end';
            })();
        }, label: 'streamed');

        foreach ($stream as $value) {
            $seen[] = 'reader: '.(app(AiContext::class)->current()['label'] ?? 'none');
        }

        expect($seen)->toBe(['streamed', 'reader: none', 'streamed', 'reader: none'])
            ->and($stream->getReturn())->toBe('end');
    });

    it('throws what the reader throws into the generator inside it, and goes on with what it yields', function () {
        $stream = Lumberjack::ai(function () {
            return (function () {
                try {
                    yield 1;
                } catch (RuntimeException $e) {
                    yield app(AiContext::class)->current()['label'].': '.$e->getMessage();
                }
            })();
        }, label: 'streamed');

        $stream->current();

        expect($stream->throw(new RuntimeException('stop')))->toBe('streamed: stop');
    });

    it('keeps the innermost scope for a stream that comes back through an outer one', function () {
        $stream = new class () implements IteratorAggregate {
            public string $invocationId = 'inv-1';

            public function getIterator(): Iterator
            {
                yield 1;
            }
        };

        Lumberjack::ai(fn () => Lumberjack::ai(fn () => $stream, owner: $this->tenant), by: Customer::make(17));

        expect(app(AiContext::class)->current('inv-1'))->toMatchArray(['owner' => ExeQue\Lumberjack\Activity\Reference::of($this->tenant), 'by' => ExeQue\Lumberjack\Activity\Reference::of(Customer::make(17))]);
    });

    it('runs a streamed response\'s callback inside the scope', function () {
        $label = null;
        $response = Lumberjack::ai(function () use (&$label) {
            return new Symfony\Component\HttpFoundation\StreamedResponse(function () use (&$label): void {
                $label = app(AiContext::class)->current()['label'];
            });
        }, label: 'streamed');

        $response->sendContent();

        expect($label)->toBe('streamed');
    });

    it('runs a streamed response sent after nested scopes closed inside both', function () {
        $seen = null;
        $response = Lumberjack::ai(function () use (&$seen) {
            return Lumberjack::ai(function () use (&$seen) {
                return new Symfony\Component\HttpFoundation\StreamedResponse(function () use (&$seen): void {
                    $seen = app(AiContext::class)->current();
                });
            }, label: 'inner');
        }, owner: $this->tenant, label: 'outer');

        $response->sendContent();

        expect($seen['label'])->toBe('inner')
            ->and($seen['owner']?->toArray())->toBe(['type' => 'tenant', 'id' => '42']);
    });

    it('ends a generator the reader stops early inside the scope', function () {
        $label = null;
        $stream = Lumberjack::ai(function () use (&$label) {
            return (function () use (&$label) {
                try {
                    yield 1;
                    yield 2;
                } finally {
                    $label = app(AiContext::class)->current()['label'];
                }
            })();
        }, label: 'streamed');

        foreach ($stream as $value) {
            break;
        }
        unset($stream);

        expect($label)->toBe('streamed');
    });

    it('leaves the scope when the callback throws', function () {
        expect(fn () => Lumberjack::ai(fn () => throw new RuntimeException('boom'), owner: $this->tenant))->toThrow(RuntimeException::class, 'boom');

        expect(app(AiContext::class)->current()['owner'])->toBeNull();
    });

    it('lets go of stream scopes for the next queued job and the next Octane request, but not of open scopes', function (Closure $next) {
        $context = app(AiContext::class);
        $context->run(fn () => boundStream('inv-next'), label: 'bound');

        $context->run(function () use ($context, $next) {
            $next();
            expect($context->current()['label'])->toBe('open');
        }, label: 'open');

        expect($context->current('inv-next')['label'])->toBeNull();
    })->with([
        'queued job' => [fn () => event(new JobProcessing('redis', new TestJob()))],
        'Octane request' => [fn () => event(new RequestReceived(app(), app(), Request::create('/next')))],
    ]);

    it('lets the resolver decide before the scope, with the key the call was made with', function () {
        Lumberjack::resolveAiKeyUsing(fn (AiCall $call): ?array => $call->usesKey('backup-key') ? ['label' => 'backup', 'owner' => new Actor('team', 7)] : null);

        Lumberjack::ai(function () {
            Lumberjack::aiUsage(provider: 'openai', model: 'gpt-5', key: 'backup-key');
            Lumberjack::aiUsage(provider: 'openai', model: 'gpt-5', key: 'primary-key');
        }, label: 'scoped');

        [$backup, $primary] = ($this->calls)();
        expect($backup['key'])->toMatchArray(['label' => 'backup', 'owner' => ['type' => 'team', 'id' => '7']])
            ->and($primary['key']['label'])->toBe('scoped');
    });

    it('lets what aiUsage() is given win over the resolver', function () {
        Lumberjack::resolveAiKeyUsing(fn (): array => ['label' => 'resolved']);

        Lumberjack::aiUsage(provider: 'openai', model: 'gpt-5', label: 'given');

        expect(($this->calls)()[0]['key']['label'])->toBe('given');
    });
});

describe('testing helpers', function () {
    it('lists and asserts AI calls', function () {
        Lumberjack::fake();

        Lumberjack::ai(fn () => Lumberjack::aiUsage(provider: 'openai', model: 'gpt-5', input: 10, output: 3, key: 'k'), by: Customer::make(17), owner: $this->tenant);

        Lumberjack::assertRecorded(EventType::AiCall);
        Lumberjack::assertAiCallRecorded('gpt-5', 'openai', times: 1);
        Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->usesKey('k') && $call->isOwnedBy('tenant', 42) && $call->isBy(Customer::class, 17) && $call->inputTokens === 10);
        Lumberjack::assertAiCallNotRecorded('gpt-4');
        Lumberjack::assertAiCallNotRecorded(provider: 'anthropic');

        expect(fn () => Lumberjack::assertAiCallRecorded('gpt-4'))->toThrow(AssertionFailedError::class, 'openai/gpt-5 (text, ok, in 10, out 3)');
    });
});

describe('without Laravel AI and Prism', function () {
    it('boots, skips both integrations and still records by hand', function () {
        $events = app('events');

        Lumberjack::aiUsage(provider: 'openai', model: 'gpt-5', input: 1);

        expect($events->getRawListeners())->not->toHaveKey('Laravel\Ai\Events\StepCompleted')
            ->and(app()->bound('Prism\Prism\PrismManager'))->toBeFalse()
            ->and(($this->calls)())->toHaveCount(1);
    })->skip(fn (): bool => class_exists('Laravel\Ai\Events\StepCompleted') || class_exists('Prism\Prism\PrismManager'), 'Runs in the CI job that removes laravel/ai and prism-php/prism.');
});
