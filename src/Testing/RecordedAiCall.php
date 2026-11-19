<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing;

use ExeQue\Lumberjack\Ai\AiCall;
use SensitiveParameter;

/**
 * An AI call as Lumberjack would receive it.
 */
final readonly class RecordedAiCall
{
    /**
     * @param  array{type: string, id: string}|null  $owner  the key's owner
     * @param  array{type: string, id: string}|null  $actor
     */
    public function __construct(
        public string $source,
        public string $provider,
        public string $driver,
        public string $model,
        public string $operation,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?int $cacheReadTokens,
        public ?int $cacheWriteTokens,
        public ?int $reasoningTokens,
        public ?float $durationMs,
        public string $status,
        public ?string $error,
        public ?string $keySha256,
        public ?string $label,
        public ?array $owner,
        public ?array $actor,
        public ?string $invocationId,
        public ?int $step,
    ) {
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function fromEvent(array $event): self
    {
        return new self(
            source: $event['source'],
            provider: $event['provider'],
            driver: $event['driver'],
            model: $event['model'],
            operation: $event['operation'],
            inputTokens: $event['tokens']['input'] ?? null,
            outputTokens: $event['tokens']['output'] ?? null,
            cacheReadTokens: $event['tokens']['cache_read'] ?? null,
            cacheWriteTokens: $event['tokens']['cache_write'] ?? null,
            reasoningTokens: $event['tokens']['reasoning'] ?? null,
            durationMs: $event['duration_ms'] ?? null,
            status: $event['status'],
            error: $event['error'] ?? null,
            keySha256: $event['key']['sha256'] ?? null,
            label: $event['key']['label'] ?? null,
            owner: $event['key']['owner'] ?? null,
            actor: $event['actor'] ?? null,
            invocationId: $event['invocation_id'] ?? null,
            step: $event['step'] ?? null,
        );
    }

    /** Whether it was made with this key. */
    public function usesKey(#[SensitiveParameter] ?string $key): bool
    {
        $fingerprint = AiCall::fingerprint($this->driver, $key);

        return $fingerprint !== null && $this->keySha256 === $fingerprint;
    }

    /** Whether the actor is this type and key. */
    public function isBy(string $type, string|int $id): bool
    {
        return $this->actor === ['type' => $type, 'id' => (string) $id];
    }

    /** Whether the key belongs to this type and key. */
    public function isOwnedBy(string $type, string|int $id): bool
    {
        return $this->owner === ['type' => $type, 'id' => (string) $id];
    }

    public function failed(): bool
    {
        return $this->status === 'error';
    }
}
