<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Ai;

/**
 * Token counts as Lumberjack keeps them, whatever the client reported: `input` is every
 * input token INCLUDING cache reads and writes (the OpenTelemetry convention), `output`
 * every output token INCLUDING reasoning. What each client reports, checked against its
 * response mappers (Laravel AI 0.11.3 and 1.4; Prism 0.100 to 0.101, "0.10x" below,
 * and 0.102 to 0.103, which moved every driver to one MapsUsage convention):
 *
 * | Client         | Driver                         | Operation           | Input reported              | Output reported        | Done here                          |
 * |----------------|--------------------------------|---------------------|-----------------------------|------------------------|------------------------------------|
 * | Laravel AI 1.x | every one                      | every one           | total, cache included       | reasoning included     | as is                              |
 * | Laravel AI 0.x | every one                      | every one           | cache left out (promptTokens) | reasoning included   | input + cache read + cache write   |
 * | Laravel AI 0.x | gemini                         | every one           | cache left out              | thoughts left out      | also output + reasoning            |
 * | Prism 0.102+   | every one                      | every one           | cache left out (promptTokens) | reasoning included   | input + cache read + cache write   |
 * | Prism 0.10x    | anthropic                      | every one           | cache left out              | thinking included      | input + cache read + cache write   |
 * | Prism 0.10x    | openai                         | text, structured    | cached tokens subtracted    | reasoning included     | input + cache read                 |
 * | Prism 0.10x    | openai                         | stream              | total, cache included       | reasoning included     | as is                              |
 * | Prism 0.10x    | gemini                         | text, stream with a cachedContentName | cached tokens subtracted | thoughts left out | input + cache read, output + reasoning |
 * | Prism 0.10x    | gemini                         | otherwise           | total, cache included       | thoughts left out      | output + reasoning                 |
 * | Prism 0.10x    | openrouter, deepseek, groq, mistral, xai, ollama, perplexity, z, others | every one | total (or no cache reported) | as reported | as is |
 * | By hand        | any                            | any                 | asked for the total         | asked for the total    | as is                              |
 *
 * Laravel AI 0.x subtracts cached tokens for the OpenAI-style drivers (OpenAI, Azure,
 * Gemini, xAI, Groq, DeepSeek, OpenRouter) and takes Anthropic's and Bedrock's own,
 * which leave the cache out; 1.x adds the cache back for those two instead.
 */
final class TokenNormalizer
{
    /**
     * @param  bool  $legacy  Laravel AI before 1.0 (Usage::$promptTokens)
     * @return array{inputTokens: ?int, outputTokens: ?int, cacheReadTokens: ?int, cacheWriteTokens: ?int, reasoningTokens: ?int}
     */
    public static function laravelAi(bool $legacy, string $driver, ?int $input, ?int $output, ?int $cacheRead = null, ?int $cacheWrite = null, ?int $reasoning = null): array
    {
        return self::tokens($input, $output, $cacheRead, $cacheWrite, $reasoning, addCache: $legacy, addReasoning: $legacy && $driver === 'gemini');
    }

    /**
     * @param  bool  $explicitCache  a Gemini request naming a cachedContentName
     * @param  bool  $uniform  Prism 0.102 or later: every driver leaves the cache out of promptTokens
     * @return array{inputTokens: ?int, outputTokens: ?int, cacheReadTokens: ?int, cacheWriteTokens: ?int, reasoningTokens: ?int}
     */
    public static function prism(string $driver, string $operation, ?int $input, ?int $output, ?int $cacheRead = null, ?int $cacheWrite = null, ?int $reasoning = null, bool $explicitCache = false, bool $uniform = false): array
    {
        if ($uniform) {
            return self::tokens($input, $output, $cacheRead, $cacheWrite, $reasoning, addCache: true, addReasoning: false);
        }

        $addCache = match ($driver) {
            'anthropic' => true,
            'openai' => $operation !== 'stream',
            'gemini' => $explicitCache && in_array($operation, ['text', 'stream'], true),
            default => false,
        };

        return self::tokens($input, $output, $cacheRead, $cacheWrite, $reasoning, $addCache, addReasoning: $driver === 'gemini');
    }

    /**
     * @return array{inputTokens: ?int, outputTokens: ?int, cacheReadTokens: ?int, cacheWriteTokens: ?int, reasoningTokens: ?int}
     */
    private static function tokens(?int $input, ?int $output, ?int $cacheRead, ?int $cacheWrite, ?int $reasoning, bool $addCache, bool $addReasoning): array
    {
        return [
            'inputTokens' => $input === null || ! $addCache ? $input : $input + ($cacheRead ?? 0) + ($cacheWrite ?? 0),
            'outputTokens' => $output === null || ! $addReasoning ? $output : $output + ($reasoning ?? 0),
            'cacheReadTokens' => $cacheRead,
            'cacheWriteTokens' => $cacheWrite,
            'reasoningTokens' => $reasoning,
        ];
    }
}
