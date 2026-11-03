<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack;

use Illuminate\Container\Container;
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
    ) {
    }

    public function render(): string
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
                'data-user' => $this->userId(),
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
