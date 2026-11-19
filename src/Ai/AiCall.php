<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Ai;

use SensitiveParameter;

/**
 * One call to an AI provider, as Lumberjack records it: never the prompt, the response or
 * the API key, only a fingerprint of the key. `inputTokens` counts every input token,
 * cached ones included; `outputTokens` every output token, reasoning included.
 */
final readonly class AiCall
{
    public function __construct(
        public string $source,
        public string $provider,
        public string $driver,
        public string $model,
        public string $operation = 'text',
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $cacheReadTokens = null,
        public ?int $cacheWriteTokens = null,
        public ?int $reasoningTokens = null,
        public ?float $durationMs = null,
        public ?string $error = null,
        public ?string $keySha256 = null,
        public ?string $invocationId = null,
        public ?int $step = null,
    ) {
    }

    /**
     * What Lumberjack knows a key by: sha256 of "driver:key" with the driver in lowercase,
     * in lowercase hex. The key itself never leaves the app. Null without a key.
     */
    public static function fingerprint(string $driver, #[SensitiveParameter] ?string $key): ?string
    {
        return $key === null || $key === '' ? null : hash('sha256', strtolower($driver).':'.$key);
    }

    /** Whether the call was made with this key. */
    public function usesKey(#[SensitiveParameter] ?string $key): bool
    {
        $fingerprint = self::fingerprint($this->driver, $key);

        return $fingerprint !== null && $this->keySha256 !== null && hash_equals($this->keySha256, $fingerprint);
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }
}
