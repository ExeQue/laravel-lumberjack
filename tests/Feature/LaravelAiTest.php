<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Activity\Actor;
use ExeQue\Lumberjack\Ai\AiContext;
use ExeQue\Lumberjack\Ai\LaravelAi\RecordsLaravelAi;
use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\Testing\RecordedAiCall;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Providers\OpenAiProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;
use Tests\Fixtures\Ai\EmbedTool;
use Tests\Fixtures\Ai\OwnedAgent;
use Tests\Fixtures\Ai\OwnerOnlyAgent;
use Tests\Fixtures\Ai\PlatformTool;
use Tests\Fixtures\Ai\RememberingAgent;
use Tests\Fixtures\Ai\SubAgentTool;
use Tests\Fixtures\Ai\UntellableActor;
use Tests\Support\TestJob;

covers(RecordsLaravelAi::class);

beforeEach(function () {
    if (! class_exists(StepCompleted::class)) {
        $this->markTestSkipped('laravel/ai is not installed.');
    }

    config()->set('ai.providers.anthropic', ['driver' => 'anthropic', 'key' => 'sk-ant-secret']);
    config()->set('ai.providers.openai', ['driver' => 'openai', 'key' => 'sk-openai-secret']);
    Lumberjack::fake();
    $this->assistant = new AnonymousAgent('You help.', [], []);
});

/**
 * Usage as either version builds it: 1.x counts cached tokens in the input, 0.x leaves them out.
 */
function laravelAiUsage(int $input = 0, int $output = 0, ?int $cacheRead = null, ?int $cacheWrite = null, ?int $reasoning = null): object
{
    return class_exists(Laravel\Ai\Responses\Data\TextUsage::class)
        ? new Laravel\Ai\Responses\Data\TextUsage($input, $output, $cacheRead, $cacheWrite, $reasoning)
        : new Laravel\Ai\Responses\Data\Usage($input - (int) $cacheRead - (int) $cacheWrite, $output, (int) $cacheWrite, (int) $cacheRead, (int) $reasoning);
}

/**
 * @param  list<list<float>>  $vectors
 */
function laravelAiEmbeddings(array $vectors, int $tokens, Meta $meta): Laravel\Ai\Responses\EmbeddingsResponse
{
    return class_exists(Laravel\Ai\Responses\Data\TextUsage::class)
        ? new Laravel\Ai\Responses\EmbeddingsResponse($vectors, new Laravel\Ai\Responses\Data\Usage($tokens), $meta)
        : new Laravel\Ai\Responses\EmbeddingsResponse($vectors, $tokens, $meta);
}

it('records each text step with its tokens and the fingerprint of the key', function () {
    AnonymousAgent::fake([new TextResponse('Hi', laravelAiUsage(1200, 300, 800, 100, 50), new Meta('anthropic', 'claude-sonnet-4-5-20250929'))]);

    Lumberjack::ai(fn () => $this->assistant->prompt('Hello', provider: 'anthropic', model: 'claude-sonnet-4-5'), owner: new Actor('tenant', 42));

    $call = Lumberjack::aiCalls()[0];
    expect($call)->toMatchObject([
        'source' => 'laravel-ai',
        'provider' => 'anthropic',
        'driver' => 'anthropic',
        'model' => 'claude-sonnet-4-5-20250929',
        'operation' => 'text',
        'inputTokens' => 1200,
        'outputTokens' => 300,
        'cacheReadTokens' => 800,
        'cacheWriteTokens' => 100,
        'reasoningTokens' => 50,
        'status' => 'ok',
        'step' => 0,
        'keySha256' => hash('sha256', 'anthropic:sk-ant-secret'),
        'owner' => ['type' => 'tenant', 'id' => '42'],
    ])->and($call->invocationId)->not->toBeNull()
        ->and(json_encode(Lumberjack::recorded()))->not->toContain('sk-ant-secret');
});

it('records a streamed step as a stream', function () {
    AnonymousAgent::fake([new TextResponse('Streamed reply', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);

    foreach ($this->assistant->stream('Hello', provider: 'openai', model: 'gpt-5') as $event) {
        // read to the end
    }

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->operation === 'stream' && $call->inputTokens === 10 && $call->usesKey('sk-openai-secret'), times: 1);
});

it('keeps the Lumberjack::ai() scope for a stream read after the callback returned', function () {
    AnonymousAgent::fake([new TextResponse('Streamed reply', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);

    $stream = Lumberjack::ai(fn () => $this->assistant->stream('Hello', provider: 'openai', model: 'gpt-5'), owner: new Actor('tenant', 42), label: 'byok');

    foreach ($stream as $event) {
        // read to the end, outside the scope
    }

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->operation === 'stream' && $call->isOwnedBy('tenant', 42) && $call->label === 'byok', times: 1);
});

it('records a stream the reader stopped early, without tokens, when the request ends', function () {
    AnonymousAgent::fake([new TextResponse('A long streamed reply', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);

    $stream = Lumberjack::ai(fn () => $this->assistant->stream('Hello', provider: 'openai', model: 'gpt-5'), label: 'byok');

    foreach ($stream as $event) {
        break;
    }

    Lumberjack::assertAiCallNotRecorded();
    app()->terminate();

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->operation === 'stream' && $call->status === 'ok' && $call->inputTokens === null && $call->step === 0 && $call->label === 'byok', times: 1);
});

it('keeps the innermost scope for a stream that comes back through an outer one', function () {
    AnonymousAgent::fake([new TextResponse('Streamed reply', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);

    $stream = Lumberjack::ai(fn () => Lumberjack::ai(fn () => $this->assistant->stream('Hello', provider: 'openai', model: 'gpt-5'), owner: new Actor('tenant', 42), label: 'byok'), by: new Actor('user', 7));

    foreach ($stream as $event) {
        // read to the end, outside both scopes
    }

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->isOwnedBy('tenant', 42) && $call->label === 'byok' && $call->isBy('user', 7), times: 1);
});

it('keeps the scope for an AI call a tool makes while a stream is read outside it', function () {
    $agent = new AnonymousAgent('You help.', [], [new EmbedTool()]);
    AnonymousAgent::fake([new ToolCall('call_1', 'EmbedTool', []), new TextResponse('Found it', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);

    $stream = Lumberjack::ai(fn () => $agent->stream('Find it', provider: 'openai', model: 'gpt-5'), owner: new Actor('tenant', 42));

    foreach ($stream as $event) {
        // read to the end, outside the scope
    }

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->model === 'voyage-3' && $call->isOwnedBy('tenant', 42), times: 1);
    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->model === 'gpt-5' && $call->isOwnedBy('tenant', 42), times: 2);
});

it('records a stream left open when a queued job ends', function (Closure $ended) {
    AnonymousAgent::fake([new TextResponse('A long streamed reply', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);

    foreach ($this->assistant->stream('Hello', provider: 'openai', model: 'gpt-5') as $event) {
        break;
    }

    event($ended(new TestJob()));

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->operation === 'stream' && $call->inputTokens === null, times: 1);
})->with([
    'processed' => [fn (TestJob $job) => new JobProcessed('redis', $job)],
    'failed' => [fn (TestJob $job) => new JobFailed('redis', $job, new RuntimeException('boom'))],
    'released' => [fn (TestJob $job) => new JobReleasedAfterException('redis', $job)],
]);

it('leaves a stream open in a sync job alone, as the request still reads it', function () {
    AnonymousAgent::fake([new TextResponse('A long streamed reply', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);

    foreach ($this->assistant->stream('Hello', provider: 'openai', model: 'gpt-5') as $event) {
        break;
    }

    event(new JobProcessed('sync', new Illuminate\Queue\Jobs\SyncJob(app(), '{"job":"x"}', 'sync', 'default')));

    Lumberjack::assertAiCallNotRecorded();
});

it('lets a scope a tool opens win over the stream it runs in', function () {
    $agent = new AnonymousAgent('You help.', [], [new PlatformTool()]);
    AnonymousAgent::fake([new ToolCall('call_1', 'PlatformTool', []), new TextResponse('Found', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);

    $stream = Lumberjack::ai(fn () => $agent->stream('Find it', provider: 'openai', model: 'gpt-5'), owner: new Actor('tenant', 42), label: 'byok');

    foreach ($stream as $event) {
        // read to the end, outside the scope
    }

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->model === 'voyage-3' && $call->isOwnedBy('platform', 1) && $call->label === 'platform', times: 1);
    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->model === 'gpt-5' && $call->isOwnedBy('tenant', 42), times: 2);
});

it('lets a tool\'s scope win over a stream peeked at in a scope and read again outside it', function (bool $nested) {
    $agent = new AnonymousAgent('You help.', [], [new PlatformTool()]);
    AnonymousAgent::fake([
        new TextResponse('Peeked', laravelAiUsage(1, 1), new Meta('openai', 'gpt-5')),
        new ToolCall('call_1', 'PlatformTool', []),
        new TextResponse('Found', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5')),
    ]);

    $peek = function () use ($agent) {
        $stream = $agent->stream('Find it', provider: 'openai', model: 'gpt-5');

        foreach ($stream as $event) {
            break;
        }

        return $stream;
    };

    $stream = $nested
        ? Lumberjack::ai(fn () => Lumberjack::ai($peek, label: 'byok'), owner: new Actor('tenant', 42))
        : Lumberjack::ai($peek, owner: new Actor('tenant', 42), label: 'byok');

    foreach ($stream as $event) {
        // read again, outside the scopes
    }

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->model === 'voyage-3' && $call->isOwnedBy('platform', 1) && $call->label === 'platform', times: 1);
    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->model === 'gpt-5' && $call->isOwnedBy('tenant', 42) && $call->label === 'byok', times: 3);
    expect(Lumberjack::aiCalls())->toHaveCount(4);
})->with(['in one scope' => false, 'in a nested scope' => true]);

it('keeps the stream\'s scope while a reader in a scope of its own reads it a step at a time', function (bool $closure) {
    $agent = new AnonymousAgent('You help.', [], [new EmbedTool()]);
    AnonymousAgent::fake([new ToolCall('call_1', 'EmbedTool', []), new TextResponse('Found', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);

    $stream = Lumberjack::ai(fn () => $agent->stream('Find it', provider: 'openai', model: 'gpt-5'), owner: new Actor('tenant', 42), label: 'byok');

    if ($closure) {
        Lumberjack::ai(fn () => new Symfony\Component\HttpFoundation\StreamedResponse(function () use ($stream): void {
            foreach ($stream as $event) {
                // read inside the reader's scope
            }
        }), owner: new Actor('reader', 1))->sendContent();
    } else {
        $reader = Lumberjack::ai(fn () => (function () use ($stream) {
            foreach ($stream as $event) {
                yield $event;
            }
        })(), owner: new Actor('reader', 1));

        foreach ($reader as $event) {
            // resumed step by step, re-entering the reader's scope
        }
    }

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->isOwnedBy('tenant', 42) && $call->label === 'byok', times: 3);
    expect(Lumberjack::aiCalls())->toHaveCount(3);
})->with(['generator' => false, 'closure' => true]);

it('keeps the stream\'s scope for a stream started before a reader\'s scope opened', function (string $reader) {
    $agent = new AnonymousAgent('You help.', [], [new EmbedTool()]);
    AnonymousAgent::fake([new ToolCall('call_1', 'EmbedTool', []), new TextResponse('Found', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);

    $events = Lumberjack::ai(fn () => $agent->stream('Find it', provider: 'openai', model: 'gpt-5'), owner: new Actor('tenant', 42), label: 'byok')->getIterator();
    $events->current();

    $read = function () use ($events): void {
        foreach ($events as $event) {
            // read on, inside the reader's scope
        }
    };

    match ($reader) {
        'callback' => Lumberjack::ai($read, owner: new Actor('reader', 1)),
        'generator' => iterator_to_array(Lumberjack::ai(fn () => (function () use ($read) {
            $read();

            yield 1;
        })(), owner: new Actor('reader', 1))),
        'streamed response' => Lumberjack::ai(fn () => new Symfony\Component\HttpFoundation\StreamedResponse($read), owner: new Actor('reader', 1))->sendContent(),
    };

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->isOwnedBy('tenant', 42) && $call->label === 'byok', times: 3);
    expect(Lumberjack::aiCalls())->toHaveCount(3);
})->with(['callback', 'generator', 'streamed response']);

it('keeps a tool\'s scope for a sub-agent that fails over to another provider', function () {
    $turns = 0;
    AnonymousAgent::fake(function (string $prompt, $attachments, $provider) use (&$turns) {
        return match (true) {
            $prompt === 'Sub question' && $provider->name() === 'openai' => throw RateLimitedException::forProvider('openai'),
            $prompt === 'Sub question' => new TextResponse('From Claude', laravelAiUsage(5, 2), new Meta('anthropic', 'claude-sonnet-4-5')),
            $turns++ === 0 => new ToolCall('call_1', 'SubAgentTool', []),
            default => new TextResponse('Found', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5')),
        };
    });
    $sub = new AnonymousAgent('You know.', [], []);
    $tool = new SubAgentTool(fn () => Lumberjack::ai(fn () => $sub->prompt('Sub question', provider: ['openai' => 'gpt-5-mini', 'anthropic' => 'claude-sonnet-4-5']), owner: new Actor('platform', 1)));
    $agent = new AnonymousAgent('You help.', [], [$tool]);

    $stream = Lumberjack::ai(fn () => $agent->stream('Find it', provider: 'openai', model: 'gpt-5'), owner: new Actor('tenant', 42));

    foreach ($stream as $event) {
        // read to the end, outside the scope
    }

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->model !== 'gpt-5' && $call->isOwnedBy('platform', 1), times: 2);
    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->model === 'gpt-5' && $call->isOwnedBy('tenant', 42), times: 2);
});

it('gives a sub-agent in a tool the stream\'s scope, unless it opens its own', function (bool $ownScope) {
    $ask = fn () => (new AnonymousAgent('You know.', [], []))->prompt('Sub question', provider: 'openai', model: 'gpt-5-mini');
    $tool = new SubAgentTool($ownScope ? fn () => Lumberjack::ai($ask, owner: new Actor('platform', 1)) : $ask);
    $agent = new AnonymousAgent('You help.', [], [$tool]);
    AnonymousAgent::fake([
        new ToolCall('call_1', 'SubAgentTool', []),
        new TextResponse('Sub reply', laravelAiUsage(3, 1), new Meta('openai', 'gpt-5-mini')),
        new TextResponse('Found', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5')),
    ]);

    $stream = Lumberjack::ai(fn () => $agent->stream('Find it', provider: 'openai', model: 'gpt-5'), owner: new Actor('tenant', 42));

    foreach ($stream as $event) {
        // read to the end, outside the scope
    }

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->model === 'gpt-5-mini' && ($ownScope ? $call->isOwnedBy('platform', 1) : $call->isOwnedBy('tenant', 42)), times: 1);
})->with(['inherited' => false, 'its own' => true]);

it('keeps the scope for a stream left open inside the callback, recorded once the request ends', function () {
    AnonymousAgent::fake([new TextResponse('A long reply', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);

    Lumberjack::ai(function () {
        foreach ($this->assistant->stream('Hello', provider: 'openai', model: 'gpt-5') as $event) {
            break;
        }
    }, owner: new Actor('tenant', 42));
    app()->terminate();

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->operation === 'stream' && $call->isOwnedBy('tenant', 42), times: 1);
});

it('records a step started again as left open, and a non-streamed one as text', function () {
    $step = fn () => new StartingStep('inv-1', 0, $this->assistant, Ai::textProvider('openai'), 'gpt-5', true, [], null);

    event($step());
    event($step());

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->operation === 'text' && $call->step === 0 && $call->inputTokens === null, times: 1);
    app()->terminate();
    Lumberjack::assertAiCallRecorded(times: 2);
});

it('records a step pushed out of the open steps as it goes', function () {
    foreach (range(0, RecordsLaravelAi::MAX_OPEN) as $number) {
        event(new StartingStep('inv-'.$number, 0, $this->assistant, Ai::textProvider('openai'), 'gpt-5', true, [], null));
    }

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->invocationId === 'inv-0', times: 1);
});

it('records every step left open even when one of them cannot be', function () {
    $broken = Mockery::mock(OpenAiProvider::class);
    $broken->shouldReceive('driver')->andThrow(new RuntimeException('broken'));

    event(new StartingStep('inv-broken', 0, $this->assistant, $broken, 'gpt-5', true, [], null));
    event(new StartingStep('inv-fine', 0, $this->assistant, Ai::textProvider('openai'), 'gpt-5', true, [], null));
    app()->terminate();

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->invocationId === 'inv-fine', times: 1);
});

it('records a step that fails after it was recorded as left open only once', function () {
    event(new StartingStep('inv-1', 0, $this->assistant, Ai::textProvider('openai'), 'gpt-5', true, [], null));
    event(new StartingStep('inv-1', 0, $this->assistant, Ai::textProvider('openai'), 'gpt-5', true, [], null));
    app()->terminate();
    event(new StepFailed('inv-1', 0, $this->assistant, Ai::textProvider('openai'), 'gpt-5', true, new RuntimeException('late'), 10.0));

    Lumberjack::assertAiCallRecorded(times: 2);
    Lumberjack::assertAiCallNotRecorded(fn (RecordedAiCall $call): bool => $call->failed());
});

it('records each failed attempt at the same step, as when every provider fails over', function () {
    AnonymousAgent::fake(fn (string $prompt, $attachments, $provider) => throw RateLimitedException::forProvider($provider->name()));

    expect(fn () => $this->assistant->prompt('Hello', provider: ['openai' => 'gpt-5', 'anthropic' => 'claude-sonnet-4-5']))->toThrow(RateLimitedException::class);

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->failed() && $call->step === 0, times: 2);
});

it('layers a stream bound inside a tool over the stream the tool runs in', function () {
    $sub = new AnonymousAgent('You know.', [], []);
    $tool = new SubAgentTool(function () use ($sub): void {
        foreach (Lumberjack::ai(fn () => $sub->stream('Sub question', provider: 'openai', model: 'gpt-5-mini'), label: 'platform') as $event) {
            // read to the end
        }
    });
    $agent = new AnonymousAgent('You help.', [], [$tool]);
    AnonymousAgent::fake([
        new ToolCall('call_1', 'SubAgentTool', []),
        new TextResponse('Sub reply', laravelAiUsage(3, 1), new Meta('openai', 'gpt-5-mini')),
        new TextResponse('Found', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5')),
    ]);

    $stream = Lumberjack::ai(fn () => $agent->stream('Find it', provider: 'openai', model: 'gpt-5'), owner: new Actor('tenant', 42), label: 'byok');

    foreach ($stream as $event) {
        // read to the end, outside the scope
    }

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->model === 'gpt-5-mini' && $call->isOwnedBy('tenant', 42) && $call->label === 'platform', times: 1);
});

it('records a step that fails without failing over as an error', function () {
    AnonymousAgent::fake(fn () => throw new RuntimeException('provider down'));

    expect(fn () => $this->assistant->prompt('Hello', provider: 'openai', model: 'gpt-5'))->toThrow(RuntimeException::class);

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->failed() && $call->error === 'RuntimeException' && $call->operation === 'text', times: 1);
});

it('records the failed call of a provider that fails over, then the one that answered', function () {
    AnonymousAgent::fake(fn (string $prompt, $attachments, $provider) => $provider->name() === 'openai'
        ? throw RateLimitedException::forProvider('openai')
        : new TextResponse('From Claude', laravelAiUsage(5, 2), new Meta('anthropic', 'claude-sonnet-4-5')));

    $this->assistant->prompt('Hello', provider: ['openai' => 'gpt-5', 'anthropic' => 'claude-sonnet-4-5']);

    [$failed, $answered] = Lumberjack::aiCalls();
    expect($failed)->toMatchObject(['provider' => 'openai', 'status' => 'error', 'error' => 'RateLimitedException', 'inputTokens' => null])
        ->and($answered)->toMatchObject(['provider' => 'anthropic', 'status' => 'ok', 'inputTokens' => 5]);
});

it('takes the owner, actor and label from an agent that knows them', function (bool $streamed) {
    OwnedAgent::fake([new TextResponse('Reply', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);
    $agent = new OwnedAgent(new Actor('tenant', 42), new Actor('user', 7), 'byok');

    if ($streamed) {
        foreach ($agent->stream('Hello', provider: 'openai', model: 'gpt-5') as $event) {
            // read to the end
        }
    } else {
        $agent->prompt('Hello', provider: 'openai', model: 'gpt-5');
    }

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->isOwnedBy('tenant', 42) && $call->isBy('user', 7) && $call->label === 'byok', times: 1);
})->with(['prompted' => false, 'streamed' => true]);

it('lets the resolver beat an agent that knows its owner, and the agent beat the scopes', function () {
    OwnedAgent::fake([new TextResponse('Reply', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);
    Lumberjack::resolveAiKeyUsing(fn (): array => ['label' => 'resolved']);

    Lumberjack::ai(fn () => (new OwnedAgent(new Actor('tenant', 42), label: 'byok'))->prompt('Hello', provider: 'openai', model: 'gpt-5'), by: new Actor('user', 7), owner: new Actor('platform', 1), label: 'scope');

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->isOwnedBy('tenant', 42) && $call->label === 'resolved' && $call->isBy('user', 7), times: 1);
});

it('lets what a call is given beat an agent that knows its owner', function () {
    $recorder = app(ExeQue\Lumberjack\Ai\AiCallRecorder::class);

    $recorder->record(new ExeQue\Lumberjack\Ai\AiCall(source: 'laravel-ai', provider: 'openai', driver: 'openai', model: 'gpt-5', operation: 'text'), ['owner' => new Actor('platform', 1)], agent: $recorder->agentScope(new OwnedAgent(new Actor('tenant', 42), label: 'byok')));

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->isOwnedBy('platform', 1) && $call->label === 'byok', times: 1);
});

it('takes only what an agent implements, the scopes giving the rest', function () {
    OwnerOnlyAgent::fake([new TextResponse('Reply', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);

    Lumberjack::ai(fn () => (new OwnerOnlyAgent(new Actor('tenant', 42)))->prompt('Hello', provider: 'openai', model: 'gpt-5'), by: new Actor('user', 7), owner: new Actor('platform', 1), label: 'scope');

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->isOwnedBy('tenant', 42) && $call->isBy('user', 7) && $call->label === 'scope', times: 1);
});

it('leaves out only what an agent cannot say, said once, and still records the call', function () {
    OwnedAgent::fake([new TextResponse('Reply', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5')), new TextResponse('Again', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);
    $agent = new OwnedAgent(label: 'byok', throws: true);

    Lumberjack::ai(function () use ($agent): void {
        $agent->prompt('Hello', provider: 'openai', model: 'gpt-5');
        $agent->prompt('Again', provider: 'openai', model: 'gpt-5');
    }, owner: new Actor('tenant', 42), label: 'scope');

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->isOwnedBy('tenant', 42) && $call->label === 'byok', times: 2);
    expect(collect(Lumberjack::recorded())->where('type', 'log')->pluck('message')->all())
        ->toBe(['Lumberjack could not read the AI owner of agent '.OwnedAgent::class.': RuntimeException']);
});

it('records the call without an owner that cannot be told, said once', function () {
    OwnedAgent::fake([new TextResponse('Reply', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);

    (new OwnedAgent(new UntellableActor(), label: 'byok'))->prompt('Hello', provider: 'openai', model: 'gpt-5');

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->owner === null && $call->label === 'byok', times: 1);
    expect(collect(Lumberjack::recorded())->where('type', 'log')->pluck('message')->all())
        ->toBe(['Lumberjack could not tell who an AI call is for: RuntimeException']);
});

it('gives an agent\'s tools what the agent says, over the scopes around it', function (bool $streamed) {
    OwnedAgent::fake([new ToolCall('call_1', 'EmbedTool', []), new TextResponse('Found', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);
    $agent = new OwnedAgent(new Actor('tenant', 42), label: 'byok', tools: [new EmbedTool()]);

    Lumberjack::ai(function () use ($agent, $streamed): void {
        if ($streamed) {
            foreach ($agent->stream('Find it', provider: 'openai', model: 'gpt-5') as $event) {
                // read to the end
            }
        } else {
            $agent->prompt('Find it', provider: 'openai', model: 'gpt-5');
        }
    }, owner: new Actor('platform', 1), label: 'platform');

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->model === 'voyage-3' && $call->isOwnedBy('tenant', 42) && $call->label === 'byok', times: 1);
})->with(['prompted' => false, 'streamed' => true]);

it('gives a sub-agent that says nothing what the agent around it says', function () {
    AnonymousAgent::fake([new TextResponse('Sub reply', laravelAiUsage(3, 1), new Meta('openai', 'gpt-5-mini'))]);
    OwnedAgent::fake([new ToolCall('call_1', 'SubAgentTool', []), new TextResponse('Found', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);
    $tool = new SubAgentTool(fn () => $this->assistant->prompt('Sub question', provider: 'openai', model: 'gpt-5-mini'));

    (new OwnedAgent(new Actor('tenant', 42), tools: [$tool]))->prompt('Find it', provider: 'openai', model: 'gpt-5');

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->model === 'gpt-5-mini' && $call->isOwnedBy('tenant', 42), times: 1);
});

it('gives the conversation title of a streamed agent what the agent says', function () {
    if (! class_exists(Laravel\Ai\Responses\Data\TextUsage::class)) {
        $this->markTestSkipped('Laravel AI 0.x generates the title outside the agent\'s run.');
    }

    $store = Mockery::mock(Laravel\Ai\Contracts\ConversationStore::class);
    $store->allows('storeConversation')->andReturn('conversation-1');
    $store->allows('storeUserMessage')->andReturn('message-1');
    $store->allows('storeAssistantMessage')->andReturn('message-2');
    app()->instance(Laravel\Ai\Contracts\ConversationStore::class, $store);
    RememberingAgent::fake([new TextResponse('Reply', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);
    $agent = (new RememberingAgent(new Actor('tenant', 42), label: 'byok'))->forParticipant((object) ['id' => 1]);

    foreach ($agent->stream('Hello', provider: 'openai', model: 'gpt-5') as $event) {
        // read to the end
    }

    expect(Lumberjack::aiCalls())->toHaveCount(2);
    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->isOwnedBy('tenant', 42) && $call->label === 'byok', times: 2);
});

it('keeps the runs around a running one when many sub-agents start inside it', function () {
    $context = app(ExeQue\Lumberjack\Recorder::class)->aiContext();
    $none = fn (): array => ['by' => null, 'owner' => null, 'label' => null];
    $stream = new class ('parent') implements IteratorAggregate {
        public function __construct(public string $invocationId)
        {
        }

        public function getIterator(): Iterator
        {
            return new ArrayIterator([]);
        }
    };

    Lumberjack::ai(fn () => $stream, owner: new Actor('tenant', 42));
    Laravel\Ai\Gateway\ParentInvocation::within('parent', 'tool', function () use ($context, $none): void {
        foreach (range(1, AiContext::MAX_RUNS + 20) as $number) {
            $context->starting('sub-'.$number, $none);
        }
    });

    $late = Laravel\Ai\Gateway\ParentInvocation::within('parent', 'tool', function () use ($context, $none): array {
        $context->starting('late', $none);

        return $context->current('late');
    });

    expect($context->current('parent')['owner']?->id)->toBe('42')
        ->and($late['owner']?->id)->toBe('42');
});

it('keeps a stream still being read while its reader starts many other runs', function (bool $othersFinish) {
    $context = app(ExeQue\Lumberjack\Recorder::class)->aiContext();
    $agent = new AnonymousAgent('You help.', [], [new EmbedTool()]);
    AnonymousAgent::fake([new ToolCall('call_1', 'EmbedTool', []), new TextResponse('Found', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);

    $events = Lumberjack::ai(fn () => $agent->stream('Find it', provider: 'openai', model: 'gpt-5'), owner: new Actor('tenant', 42))->getIterator();
    $events->current();

    $moderator = new class ('Moderate.', [], []) extends AnonymousAgent {};
    $moderator::fake(fn () => new TextResponse('Fine', laravelAiUsage(1, 1), new Meta('openai', 'omni-moderation')));

    foreach (range(1, AiContext::MAX_RUNS + 20) as $number) {
        if ($othersFinish) {
            $moderator->prompt('Chunk '.$number, provider: 'openai', model: 'omni-moderation');
        } else {
            $context->starting('moderation-'.$number, fn (): array => ['by' => null, 'owner' => null, 'label' => null]);
        }
    }

    foreach ($events as $event) {
        // read on
    }

    // Pushed out with every run still going, the step under way keeps the scope it started in.
    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->step === 0 && $call->isOwnedBy('tenant', 42), times: 1);

    if ($othersFinish) {
        Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->isOwnedBy('tenant', 42), times: 3);
    }
})->with(['the others finish' => true, 'every run still going' => false]);

it('keeps the binding of a finished run while a run started inside it is still going', function () {
    $context = app(ExeQue\Lumberjack\Recorder::class)->aiContext();
    $none = fn (): array => ['by' => null, 'owner' => null, 'label' => null];
    $stream = new class ('parent') implements IteratorAggregate {
        public function __construct(public string $invocationId)
        {
        }

        public function getIterator(): Iterator
        {
            return new ArrayIterator([]);
        }
    };

    Lumberjack::ai(fn () => $stream, owner: new Actor('tenant', 42));
    Laravel\Ai\Gateway\ParentInvocation::within('parent', 'tool', fn () => $context->starting('tool-agent', $none));
    $context->finished('parent');

    foreach (range(1, AiContext::MAX_RUNS + 5) as $number) {
        $context->starting('other-'.$number, $none);
        $context->finished('other-'.$number);
    }

    expect($context->current('tool-agent')['owner']?->id)->toBe('42');
});

it('lets go of the runs still going when the request or a queued job ends', function (Closure $end) {
    $context = app(ExeQue\Lumberjack\Recorder::class)->aiContext();
    $none = fn (): array => ['by' => null, 'owner' => null, 'label' => null];
    $context->starting('stream', $none);

    $end();

    foreach (range(1, AiContext::MAX_RUNS + 1) as $number) {
        $context->starting('other-'.$number, $none);
        $context->finished('other-'.$number);
    }

    expect($context->knows('stream'))->toBeFalse();
})->with([
    'request' => [fn () => app()->terminate()],
    'queued job' => [fn () => event(new JobProcessed('redis', new TestJob()))],
]);

it('gives a sub-agent that knows its owner its own, and the agent around it its own', function () {
    $sub = new OwnedAgent(new Actor('platform', 1), label: 'platform');
    $tool = new SubAgentTool(fn () => $sub->prompt('Sub question', provider: 'openai', model: 'gpt-5-mini'));
    $agent = new OwnedAgent(new Actor('tenant', 42), tools: [$tool]);
    OwnedAgent::fake([
        new ToolCall('call_1', 'SubAgentTool', []),
        new TextResponse('Sub reply', laravelAiUsage(3, 1), new Meta('openai', 'gpt-5-mini')),
        new TextResponse('Found', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5')),
    ]);

    foreach ($agent->stream('Find it', provider: 'openai', model: 'gpt-5') as $event) {
        // read to the end
    }

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->model === 'gpt-5-mini' && $call->isOwnedBy('platform', 1) && $call->label === 'platform', times: 1);
    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->model === 'gpt-5' && $call->isOwnedBy('tenant', 42) && $call->label === null, times: 2);
});

it('keeps what the agent said for a stream the reader stopped early', function () {
    OwnedAgent::fake([new TextResponse('A long streamed reply', laravelAiUsage(10, 4), new Meta('openai', 'gpt-5'))]);
    $agent = new OwnedAgent(new Actor('tenant', 42), label: 'byok');

    foreach ($agent->stream('Hello', provider: 'openai', model: 'gpt-5') as $event) {
        break;
    }

    $agent->owner = new Actor('tenant', 99);
    app()->terminate();

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->operation === 'stream' && $call->inputTokens === null && $call->isOwnedBy('tenant', 42) && $call->label === 'byok', times: 1);
});

it('records embeddings', function () {
    Embeddings::fake([laravelAiEmbeddings([[0.1, 0.2]], 7, new Meta('openai', 'text-embedding-3-small'))]);

    Embeddings::for(['Hello'])->generate('openai', 'text-embedding-3-small');

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->operation === 'embeddings' && $call->inputTokens === 7 && $call->model === 'text-embedding-3-small' && $call->durationMs !== null);
});

it('records failed embeddings as an error, on 0.x once the request ends', function () {
    Embeddings::fake(fn () => throw new RuntimeException('provider down'));

    expect(fn () => Embeddings::for(['Hello'])->generate('openai', 'text-embedding-3-small'))->toThrow(RuntimeException::class);
    app()->terminate();

    $legacy = ! class_exists(Laravel\Ai\Events\EmbeddingsFailed::class);
    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->operation === 'embeddings' && $call->failed() && $call->error === ($legacy ? 'Unfinished' : 'RuntimeException'), times: 1);
});

it('records nothing from Laravel AI when its integration is turned off', function () {
    $this->withConfig(['lumberjack.ai.laravel_ai' => false]);
    Lumberjack::fake();
    AnonymousAgent::fake(['Hi']);

    (new AnonymousAgent('You help.', [], []))->prompt('Hello', provider: 'openai', model: 'gpt-5');

    Lumberjack::assertAiCallNotRecorded();
});
