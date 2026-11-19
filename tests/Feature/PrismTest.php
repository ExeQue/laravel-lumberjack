<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Activity\Actor;
use ExeQue\Lumberjack\Ai\Prism\RecordingPrismManager;
use ExeQue\Lumberjack\Ai\Prism\RecordingProvider;
use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\Testing\RecordedAiCall;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\Fixtures\Ai\CountingPrismManager;
use Tests\Fixtures\Ai\EchoProvider;
use Tests\Fixtures\Ai\OpaquePrismManager;

// Without Prism these classes cannot load, and the tests skip themselves.
if (class_exists(PrismManager::class)) {
    covers(RecordingPrismManager::class, RecordingProvider::class);
}

beforeEach(function () {
    if (! class_exists(PrismManager::class)) {
        $this->markTestSkipped('prism-php/prism is not installed.');
    }

    config()->set('prism.providers.anthropic.api_key', 'sk-ant-app-key');
    config()->set('prism.providers.openai.api_key', 'sk-openai-app-key');
    Lumberjack::fake();
});

it('records a text call with its tokens normalized and the fingerprint of the key', function () {
    Prism::fake([TextResponseFake::make()->withUsage(new Usage(400, 300, cacheWriteInputTokens: 100, cacheReadInputTokens: 700))->withMeta(new Meta('msg_1', 'claude-sonnet-4-5-20250929'))]);

    Prism::text()->using('anthropic', 'claude-sonnet-4-5')->withPrompt('Hello')->asText();

    expect(Lumberjack::aiCalls()[0])->toMatchObject([
        'source' => 'prism',
        'provider' => 'anthropic',
        'driver' => 'anthropic',
        'model' => 'claude-sonnet-4-5-20250929',
        'operation' => 'text',
        'inputTokens' => 1200,
        'outputTokens' => 300,
        'cacheReadTokens' => 700,
        'cacheWriteTokens' => 100,
        'status' => 'ok',
        'keySha256' => hash('sha256', 'anthropic:sk-ant-app-key'),
    ])->and(json_encode(Lumberjack::recorded()))->not->toContain('sk-ant-app-key');
});

it('fingerprints a provider named in another case under its lowercase driver', function () {
    Prism::fake([TextResponseFake::make()]);

    Prism::text()->using('OpenAI', 'gpt-5')->withPrompt('Hello')->asText();

    expect(Lumberjack::aiCalls()[0])->toMatchObject(['driver' => 'openai', 'keySha256' => hash('sha256', 'openai:sk-openai-app-key')]);
});

it('fingerprints the key a tenant brings with usingProviderConfig()', function () {
    Prism::fake([TextResponseFake::make()]);

    Lumberjack::ai(fn () => Prism::text()->using('openai', 'gpt-5')->usingProviderConfig(['api_key' => 'sk-tenant-key'])->withPrompt('Hello')->asText(), owner: new Actor('tenant', 42), label: 'byok');

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->usesKey('sk-tenant-key') && $call->isOwnedBy('tenant', 42) && $call->label === 'byok');
});

it('records a stream once it has been read, with the usage of its end event', function () {
    Prism::fake([TextResponseFake::make()->withText('Streamed reply')->withUsage(new Usage(50, 20, cacheReadInputTokens: 30))]);

    foreach (Prism::text()->using('anthropic', 'claude-sonnet-4-5')->withPrompt('Hello')->asStream() as $event) {
        // read to the end
    }

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->operation === 'stream' && $call->inputTokens === 80 && $call->cacheReadTokens === 30, times: 1);
});

it('keeps the Lumberjack::ai() scope for a stream read after the callback returned', function () {
    Prism::fake([TextResponseFake::make()->withText('Streamed reply')]);

    $stream = Lumberjack::ai(fn () => Prism::text()->using('openai', 'gpt-5')->withPrompt('Hello')->asStream(), owner: new Actor('tenant', 42), label: 'byok');

    foreach ($stream as $event) {
        // read to the end, outside the scope
    }

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->operation === 'stream' && $call->isOwnedBy('tenant', 42) && $call->label === 'byok', times: 1);
});

it('keeps the scope for a streamed HTTP response, which runs the call when it is sent', function () {
    Prism::fake([TextResponseFake::make()->withText('Streamed reply')]);

    $response = Lumberjack::ai(fn () => Prism::text()->using('openai', 'gpt-5')->withPrompt('Hello')->asEventStreamResponse(), label: 'byok');

    Lumberjack::assertAiCallNotRecorded();
    ob_start();
    $response->sendContent();
    ob_end_clean();

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->operation === 'stream' && $call->label === 'byok', times: 1);
});

it('records a stream that fails to start as an error', function () {
    $inner = new class () extends Provider {
        public function stream(TextRequest $request): Generator
        {
            throw new RuntimeException('cannot connect');
        }
    };
    $provider = new RecordingProvider($inner, app(ExeQue\Lumberjack\Ai\AiCallRecorder::class), 'openai', null);

    expect(fn () => iterator_to_array($provider->stream(Prism::text()->using('openai', 'gpt-5')->withPrompt('Hello')->toRequest())))->toThrow(RuntimeException::class);

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->failed() && $call->operation === 'stream');
});

it('keeps the scope for a stream the caller stops reading early', function () {
    Prism::fake([TextResponseFake::make()->withText('A long streamed reply')]);

    $stream = Lumberjack::ai(fn () => Prism::text()->using('openai', 'gpt-5')->withPrompt('Hello')->asStream(), label: 'byok');

    foreach ($stream as $event) {
        break;
    }
    unset($stream);

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->operation === 'stream' && $call->label === 'byok', times: 1);
});

it('records a stream the caller stops reading early', function () {
    Prism::fake([TextResponseFake::make()->withText('A long streamed reply')]);

    foreach (Prism::text()->using('openai', 'gpt-5')->withPrompt('Hello')->asStream() as $event) {
        break;
    }

    gc_collect_cycles();

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->operation === 'stream' && $call->inputTokens === null, times: 1);
});

it('records embeddings', function () {
    Prism::fake([EmbeddingsResponseFake::make()->withUsage(new EmbeddingsUsage(12))]);

    Prism::embeddings()->using('openai', 'text-embedding-3-small')->fromInput('Hello')->asEmbeddings();

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->operation === 'embeddings' && $call->inputTokens === 12);
});

it('records a failed call as an error and rethrows its exception as it was', function () {
    $failure = new RuntimeException('provider down');
    app(PrismManager::class)->extend('broken', fn () => new class ($failure) extends Provider {
        public function __construct(private readonly Throwable $failure)
        {
        }

        public function text(TextRequest $request): TextResponse
        {
            throw $this->failure;
        }
    });

    expect(fn () => Prism::text()->using('broken', 'm')->withPrompt('Hello')->asText())->toThrow(RuntimeException::class, 'provider down');

    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->failed() && $call->error === 'RuntimeException');
});

it('works under another decorator of the Prism manager, and a provider added with extend() reaches the real manager', function () {
    config()->set('prism.providers.echo.api_key', 'echo-key');
    app()->extend(PrismManager::class, fn (PrismManager $manager, $app) => new CountingPrismManager($manager, $app));
    $manager = app(PrismManager::class);

    $manager->extend('echo', fn () => new EchoProvider());
    Prism::text()->using('echo', 'echo-1')->withPrompt('Hello')->asText();

    expect($manager)->toBeInstanceOf(CountingPrismManager::class)
        ->and($manager->resolved)->toBe(1);
    Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call): bool => $call->provider === 'echo' && $call->usesKey('echo-key') && $call->inputTokens === 3);
});

it('records a call once under a decorator that cannot say what it wraps', function () {
    config()->set('prism.providers.echo.api_key', 'echo-key');
    app(PrismManager::class)->extend('echo', fn () => new EchoProvider());
    app()->instance(PrismManager::class, new OpaquePrismManager(app(PrismManager::class), app()));

    Prism::text()->using('echo', 'echo-1')->withPrompt('Hello')->asText();

    Lumberjack::assertAiCallRecorded(times: 1);
});

it('hands the inner stream\'s return value on', function () {
    $inner = new class () extends Provider {
        public function stream(TextRequest $request): Generator
        {
            yield 1;

            return 'done';
        }
    };
    $provider = new RecordingProvider($inner, app(ExeQue\Lumberjack\Ai\AiCallRecorder::class), 'openai', null);
    $stream = $provider->stream(Prism::text()->using('openai', 'gpt-5')->withPrompt('Hello')->toRequest());

    foreach ($stream as $event) {
        // read to the end
    }

    expect($stream->getReturn())->toBe('done');
});

it('wraps the manager once, however often it is swapped in', function () {
    Prism::fake();
    Prism::fake();

    expect(RecordingPrismManager::wraps(app(PrismManager::class)))->toBeTrue()
        ->and(app(PrismManager::class)->inner())->not->toBeInstanceOf(RecordingPrismManager::class);
});

it('supports the Prism versions it was tested with only', function (?string $version, bool $supported) {
    expect(RecordingPrismManager::supports($version))->toBe($supported);
})->with([
    ['0.100.0.0', true],
    ['0.103.0.0', true],
    ['v0.101.2', true],
    ['0.99.22.0', false],
    ['0.104.0.0', false],
    ['1.0.0.0', false],
    ['dev-main', false],
    [null, false],
]);

it('leaves a newer Prism alone and says so', function () {
    RecordingPrismManager::$versionResolver = fn (): string => '0.104.0.0';

    try {
        $this->withConfig([]);
        app(PrismManager::class);
        app(ExeQue\Lumberjack\Recorder::class)->flush();
        $said = collect($this->sentEvents('log'))->pluck('message')->implode("\n");
        Lumberjack::fake();
        Prism::fake([TextResponseFake::make()]);

        Prism::text()->using('openai', 'gpt-5')->withPrompt('Hello')->asText();
    } finally {
        RecordingPrismManager::$versionResolver = null;
    }

    expect(app(PrismManager::class))->not->toBeInstanceOf(RecordingPrismManager::class)
        ->and($said)->toContain('Prism 0.104.0.0 is not a version this SDK was tested with (0.100 to 0.103)')
        ->and(Illuminate\Support\Facades\Cache::has('lumberjack:problem:prism-version:0.104.0.0'))->toBeTrue();
    Lumberjack::assertAiCallNotRecorded();
});

it('records nothing from Prism when its integration is turned off', function () {
    $this->withConfig(['lumberjack.ai.prism' => false]);
    Lumberjack::fake();
    Prism::fake([TextResponseFake::make()]);

    Prism::text()->using('openai', 'gpt-5')->withPrompt('Hello')->asText();

    expect(app(PrismManager::class))->not->toBeInstanceOf(RecordingPrismManager::class);
    Lumberjack::assertAiCallNotRecorded();
});
