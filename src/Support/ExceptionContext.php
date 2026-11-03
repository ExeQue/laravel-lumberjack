<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * What was going on when an exception happened: the request, the user, the session
 * keys the app allows and Laravel's own Context. The Recorder scrubs it.
 *
 * The request is the one being served right now: set when a route matches (or Octane
 * hands over a request), else the container's, outside the console. Everything is
 * resolved from the current container, so a long-lived worker never reads a stale one.
 */
class ExceptionContext
{
    private ?Request $request = null;

    public function __construct(
        private readonly Scrubber $scrubber,
    ) {
    }

    /**
     * The request being served, or null once it is done.
     */
    public function serving(?Request $request): void
    {
        $this->request = $request;
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $request = $this->request();

        return array_filter([
            'request' => $request !== null ? $this->describe($request) : null,
            'user' => $this->user(),
            'session' => $request !== null ? $this->session($request) : null,
            'laravel' => $this->laravel(),
        ], fn ($value): bool => $value !== null && $value !== []);
    }

    private function request(): ?Request
    {
        if ($this->request !== null) {
            return $this->request;
        }

        $app = Container::getInstance();

        if (! $app instanceof Application || $app->runningInConsole() || ! $app->bound('request')) {
            return null;
        }

        return $app->make('request');
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Request $request): array
    {
        $route = $request->route();

        return [
            'method' => $request->method(),
            'url' => $this->scrubber->url($request->fullUrl()),
            'route' => is_object($route) ? '/'.ltrim($route->uri(), '/') : null,
            'ip' => $request->ip(),
            'headers' => array_map(fn (array $values): string => implode(', ', $values), array_filter($request->headers->all(), 'is_array')),
        ];
    }

    /**
     * @return array{id: int|string}|null
     */
    private function user(): ?array
    {
        try {
            $app = Container::getInstance();
            $id = $app->bound('auth') ? $app->make('auth')->id() : null;
        } catch (Throwable) {
            return null;
        }

        return is_int($id) || is_string($id) ? ['id' => $id] : null;
    }

    /**
     * @return array<mixed>|null
     */
    private function session(Request $request): ?array
    {
        try {
            return $request->hasSession() ? $this->scrubber->session($request->session()->all()) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function laravel(): ?array
    {
        try {
            return Context::all();
        } catch (Throwable) {
            return null;
        }
    }
}
