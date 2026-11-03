<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack;

use Illuminate\Container\Container;
use Illuminate\Foundation\Vite;
use Throwable;

/**
 * The script tag behind the @lumberjack Blade directive. Rendering it never throws
 * into the page: when anything goes wrong, the page just goes without it.
 */
class BrowserScript
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly string $url,
        private readonly ?string $publicKey,
        private readonly ?string $environment,
        private readonly bool $announce = false,
        private readonly bool $sendUser = false,
    ) {
    }

    /**
     * @param  string|null  $nonce  for a Content-Security-Policy with nonces; Vite's when null
     */
    public function render(?string $nonce = null): string
    {
        if (! $this->recorder->enabled() || $this->url === '' || $this->publicKey === null || $this->publicKey === '') {
            return '';
        }

        try {
            $attributes = array_filter([
                'src' => $this->url.'/sdk/v1.js',
                'data-key' => $this->publicKey,
                'data-environment' => $this->environment,
                'data-release' => $this->recorder->release(),
                'nonce' => $nonce ?? $this->viteNonce(),
                // Off by default: a page cached for everyone would carry one user's id.
                'data-user' => $this->sendUser ? $this->userId() : null,
                'data-trace' => $this->recorder->traceId(),
                'data-announce' => $this->announce ? 'true' : null,
            ], fn ($value): bool => $value !== null && $value !== '');

            $html = implode(' ', array_map(fn (string $name, string $value): string => $name.'="'.e($value).'"', array_keys($attributes), $attributes));

            return '<script '.$html.' defer></script>';
        } catch (Throwable $e) {
            @error_log('Lumberjack could not render its browser script: '.$e->getMessage());

            return '';
        }
    }

    /**
     * The nonce the app gave Vite (Vite::useCspNonce()), so a CSP that allows Vite's
     * scripts allows this one too. Vite is only asked when the app already made it.
     */
    private function viteNonce(): ?string
    {
        $app = Container::getInstance();

        return class_exists(Vite::class) && $app->resolved(Vite::class) ? $app->make(Vite::class)->cspNonce() : null;
    }

    private function userId(): ?string
    {
        try {
            $id = Container::getInstance()->make('auth')->guard()->id();
        } catch (Throwable) {
            return null;
        }

        return is_int($id) || is_string($id) ? (string) $id : null;
    }
}
