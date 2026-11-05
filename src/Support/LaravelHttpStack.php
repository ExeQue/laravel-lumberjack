<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory as Http;
use Psr\Http\Message\RequestInterface;

/**
 * Laravel's HTTP stack for a plain Guzzle client, such as the agent's: the app's global
 * HTTP middleware applies, and so does Http::fake() in tests.
 */
final class LaravelHttpStack
{
    /**
     * @return HandlerStack<callable(RequestInterface, array<array-key, mixed>): PromiseInterface>
     */
    public static function for(Http $http): HandlerStack
    {
        $stack = $http->buildHandlerStack();

        // Laravel 12's fakes read options only its own requests set; outermost, so they are
        // there before the fakes run.
        $stack->unshift(fn (callable $handler): callable => fn (RequestInterface $request, array $options) => $handler($request, $options + [
            'laravel_data' => [],
            'on_stats' => static function (): void {
            },
        ]));

        return $stack;
    }
}
