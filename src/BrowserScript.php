<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack;

use Illuminate\Contracts\Auth\Factory as Auth;
use Throwable;

/**
 * The script tag behind the @lumberjack Blade directive.
 */
class BrowserScript
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Auth $auth,
        private readonly string $url,
        private readonly ?string $publicKey,
        private readonly ?string $environment,
        private readonly ?string $release,
        private readonly bool $announce = false,
    ) {
    }

    public function render(): string
    {
        if ($this->url === '' || $this->publicKey === null || $this->publicKey === '') {
            return '';
        }

        $attributes = array_filter([
            'src' => $this->url.'/sdk/v1.js',
            'data-key' => $this->publicKey,
            'data-environment' => $this->environment,
            'data-release' => $this->release,
            'data-user' => $this->userId(),
            'data-trace' => $this->recorder->enabled() ? $this->recorder->traceId() : null,
            'data-announce' => $this->announce ? 'true' : null,
        ], fn ($value): bool => $value !== null && $value !== '');

        $html = implode(' ', array_map(fn (string $name, string $value): string => $name.'="'.e($value).'"', array_keys($attributes), $attributes));

        return '<script '.$html.' defer></script>';
    }

    private function userId(): ?string
    {
        try {
            $id = $this->auth->guard()->id();
        } catch (Throwable) {
            return null;
        }

        return is_int($id) || is_string($id) ? (string) $id : null;
    }
}
