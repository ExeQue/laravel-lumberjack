<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Ai\TokenNormalizer;

covers(TokenNormalizer::class);

/**
 * Reported: input 100, output 50, cache read 30, cache write 20, reasoning 10.
 */
dataset('prism', [
    'anthropic text adds both cache buckets' => ['anthropic', 'text', false, 150, 50],
    'anthropic stream adds both cache buckets' => ['anthropic', 'stream', false, 150, 50],
    'openai text adds the cached tokens it subtracted' => ['openai', 'text', false, 150, 50],
    'openai structured adds the cached tokens it subtracted' => ['openai', 'structured', false, 150, 50],
    'openai stream already includes them' => ['openai', 'stream', false, 100, 50],
    'gemini adds its thoughts to the output' => ['gemini', 'text', false, 100, 60],
    'gemini with a cachedContentName adds the cache' => ['gemini', 'text', true, 150, 60],
    'gemini structured includes the cache either way' => ['gemini', 'structured', true, 100, 60],
    'openrouter is as reported' => ['openrouter', 'stream', false, 100, 50],
    'a custom provider is as reported' => ['acme', 'text', false, 100, 50],
]);

it('normalizes Prism usage', function (string $driver, string $operation, bool $explicitCache, int $input, int $output) {
    expect(TokenNormalizer::prism($driver, $operation, 100, 50, 30, 20, 10, $explicitCache))->toBe([
        'inputTokens' => $input,
        'outputTokens' => $output,
        'cacheReadTokens' => 30,
        'cacheWriteTokens' => 20,
        'reasoningTokens' => 10,
    ]);
})->with('prism');

dataset('laravel ai', [
    '1.x is the total already' => [false, 'anthropic', 100, 50],
    '1.x gemini too' => [false, 'gemini', 100, 50],
    '0.x leaves the cache out' => [true, 'openai', 150, 50],
    '0.x gemini also leaves thoughts out' => [true, 'gemini', 150, 60],
]);

it('normalizes Laravel AI usage', function (bool $legacy, string $driver, int $input, int $output) {
    expect(TokenNormalizer::laravelAi($legacy, $driver, 100, 50, 30, 20, 10))->toMatchArray(['inputTokens' => $input, 'outputTokens' => $output]);
})->with('laravel ai');

it('adds the cache for every driver of Prism 0.102 and later, whose output already holds reasoning', function (string $driver, string $operation) {
    expect(TokenNormalizer::prism($driver, $operation, 100, 50, 30, 20, 10, uniform: true))->toMatchArray(['inputTokens' => 150, 'outputTokens' => 50]);
})->with([['openai', 'stream'], ['gemini', 'structured'], ['anthropic', 'text'], ['openrouter', 'text']]);

it('keeps unknown counts unknown', function () {
    expect(TokenNormalizer::prism('anthropic', 'text', null, null, 30))->toMatchArray(['inputTokens' => null, 'outputTokens' => null]);
});
