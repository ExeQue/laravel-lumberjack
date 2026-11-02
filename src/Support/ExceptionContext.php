<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * What was going on when an exception happened: the request, the user, the
 * session and Laravel's own Context.
 */
class ExceptionContext
{
    /**
     * @param  list<string>  $scrub  extra keys to blank before anything leaves the app
     */
    public function __construct(
        private readonly Application $app,
        private readonly array $scrub = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $context = array_filter([
            'request' => $this->request(),
            'user' => $this->user(),
            'session' => $this->session(),
            'laravel' => $this->laravel(),
        ], fn ($value): bool => $value !== null && $value !== []);

        return $this->scrub($context);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function request(): ?array
    {
        if ($this->app->runningInConsole() || ! $this->app->bound('request')) {
            return null;
        }

        /** @var Request $request */
        $request = $this->app->make('request');
        $route = $request->route();

        return [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
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
            $id = $this->app->bound('auth') ? $this->app->make('auth')->id() : null;
        } catch (Throwable) {
            return null;
        }

        return is_int($id) || is_string($id) ? ['id' => $id] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function session(): ?array
    {
        if ($this->app->runningInConsole() || ! $this->app->bound('request')) {
            return null;
        }

        $request = $this->app->make('request');

        if (! $request->hasSession()) {
            return null;
        }

        return array_diff_key($request->session()->all(), array_flip(['_flash', '_previous']));
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function scrub(array $data): array
    {
        if ($this->scrub === []) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), $this->scrub, true)) {
                $data[$key] = '[scrubbed]';
            } elseif (is_array($value)) {
                $data[$key] = $this->scrub($value);
            }
        }

        return $data;
    }
}
