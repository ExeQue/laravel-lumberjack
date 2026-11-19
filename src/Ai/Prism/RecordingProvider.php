<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Ai\Prism;

use Closure;
use ExeQue\Lumberjack\Ai\AiCall;
use ExeQue\Lumberjack\Ai\AiCallRecorder;
use ExeQue\Lumberjack\Ai\TokenNormalizer;
use ExeQue\Lumberjack\Support\Safely;
use Generator;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Audio\AudioResponse as TextToSpeechResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse as SpeechToTextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Images\Request as ImagesRequest;
use Prism\Prism\Images\Response as ImagesResponse;
use Prism\Prism\Moderation\Request as ModerationRequest;
use Prism\Prism\Moderation\Response as ModerationResponse;
use Prism\Prism\Providers\OpenAI\Concerns\MapsUsage;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

/**
 * Decorates a Prism provider: every call is recorded as an ai_call, a failed one too
 * (and its exception rethrown as it was). A stream is recorded when it ends, also when
 * the caller stops reading it early. Moderation is passed through: it costs no tokens.
 *
 * Every public method of Prism's Provider is forwarded to the provider it wraps, so a
 * "not supported" default of the base class never hides what that provider does. The
 * constraint stops at the newest Prism minor tested, as a new base method would need a
 * forward here. Code checking `instanceof` on the provider sees this wrapper; inner()
 * returns the one it wraps.
 *
 * @internal
 */
final class RecordingProvider extends Provider
{
    public function __construct(
        private readonly Provider $inner,
        private readonly AiCallRecorder $ai,
        private readonly string $name,
        private readonly ?string $keySha256,
    ) {
    }

    public function inner(): Provider
    {
        return $this->inner;
    }

    public function text(TextRequest $request): TextResponse
    {
        return $this->record('text', $request, fn (): TextResponse => $this->inner->text($request));
    }

    public function structured(StructuredRequest $request): StructuredResponse
    {
        return $this->record('structured', $request, fn (): StructuredResponse => $this->inner->structured($request));
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return $this->record('embeddings', $request, fn (): EmbeddingsResponse => $this->inner->embeddings($request));
    }

    public function images(ImagesRequest $request): ImagesResponse
    {
        return $this->record('image', $request, fn (): ImagesResponse => $this->inner->images($request));
    }

    public function textToSpeech(TextToSpeechRequest $request): TextToSpeechResponse
    {
        return $this->record('audio', $request, fn (): TextToSpeechResponse => $this->inner->textToSpeech($request));
    }

    public function speechToText(SpeechToTextRequest $request): SpeechToTextResponse
    {
        return $this->record('transcription', $request, fn (): SpeechToTextResponse => $this->inner->speechToText($request));
    }

    public function moderation(ModerationRequest $request): ModerationResponse
    {
        return $this->inner->moderation($request);
    }

    /**
     * @return Generator<mixed>
     */
    public function stream(TextRequest $request): Generator
    {
        $started = hrtime(true);
        $usage = null;
        $model = null;
        $error = null;

        try {
            $stream = $this->inner->stream($request);

            foreach ($stream as $key => $event) {
                if ($event instanceof StreamStartEvent) {
                    $model = $event->model;
                }

                if ($event instanceof StreamEndEvent) {
                    $usage = $event->usage;
                }

                yield $key => $event;
            }

            return $stream->getReturn();
        } catch (Throwable $e) {
            $error = $e;

            throw $e;
        } finally {
            // Also when the caller stops reading: what was used so far is still paid for.
            $this->report('stream', $request, $usage, $started, $error, $model);
        }
    }

    public function handleRequestException(string $model, RequestException $e): never
    {
        $this->inner->handleRequestException($model, $e);
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }

    public function __get(string $name): mixed
    {
        return $this->inner->{$name};
    }

    public function __isset(string $name): bool
    {
        return isset($this->inner->{$name});
    }

    /**
     * @template TResponse of object
     *
     * @param  Closure(): TResponse  $call
     * @return TResponse
     */
    private function record(string $operation, object $request, Closure $call): object
    {
        $started = hrtime(true);

        try {
            $response = $call();
        } catch (Throwable $e) {
            $this->report($operation, $request, null, $started, $e);

            throw $e;
        }

        $meta = $response->meta ?? null;
        $this->report($operation, $request, $response->usage ?? null, $started, null, is_object($meta) ? ($meta->model ?? null) : null);

        return $response;
    }

    private function report(string $operation, object $request, mixed $usage, int|float $started, ?Throwable $error, mixed $model = null): void
    {
        Safely::run(function () use ($operation, $request, $usage, $started, $error, $model): void {
            $asked = method_exists($request, 'model') ? $request->model() : null;
            $explicitCache = method_exists($request, 'providerOptions') && $request->providerOptions('cachedContentName') !== null;

            $this->ai->record(new AiCall(
                ...$this->tokens($operation, $usage, $explicitCache),
                source: 'prism',
                provider: $this->name,
                driver: $this->name,
                model: is_string($model) && $model !== '' ? $model : (is_string($asked) ? $asked : ''),
                operation: $operation,
                durationMs: (hrtime(true) - $started) / 1e6,
                error: $error === null ? null : class_basename($error),
                keySha256: $this->keySha256,
            ));
        });
    }

    /**
     * @return array{inputTokens: ?int, outputTokens: ?int, cacheReadTokens: ?int, cacheWriteTokens: ?int, reasoningTokens: ?int}
     */
    private function tokens(string $operation, mixed $usage, bool $explicitCache): array
    {
        // Prism 0.102 brought these traits, and with them one convention for every driver.
        $uniform = trait_exists(MapsUsage::class);

        return match (true) {
            $usage instanceof Usage => TokenNormalizer::prism($this->name, $operation, $usage->promptTokens, $usage->completionTokens, $usage->cacheReadInputTokens, $usage->cacheWriteInputTokens, $usage->thoughtTokens, $explicitCache, $uniform),
            $usage instanceof EmbeddingsUsage => TokenNormalizer::prism($this->name, $operation, $usage->tokens, null),
            default => TokenNormalizer::prism($this->name, $operation, null, null),
        };
    }
}
