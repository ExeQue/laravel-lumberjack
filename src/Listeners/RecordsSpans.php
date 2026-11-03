<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Listeners;

use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Support\Safely;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Str;

/**
 * The rest of what happens on a trace, as spans: cache reads and writes, outgoing HTTP,
 * mail, notifications and Redis commands. Each is one event of type "span" with a kind,
 * a name and a target (a key, a host and path, a class), and a duration where Laravel
 * has one to give.
 */
class RecordsSpans
{
    /** Keys the framework and this package keep for themselves. */
    public const array FRAMEWORK_KEYS = ['illuminate:*', 'laravel_cache_lock:*', 'framework/*', 'lumberjack*', 'telescope:*', 'pulse:*', 'horizon:*'];

    /**
     * @param  array{cache?: bool, http?: bool, mail?: bool, notifications?: bool, redis?: bool, ignore_cache_keys?: list<string>}  $config
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly array $config,
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        if ($this->config['cache'] ?? true) {
            $events->listen(CacheHit::class, Safely::wrap(fn (CacheHit $e) => $this->cache('hit', $e->key, $e->storeName ?? null)));
            $events->listen(CacheMissed::class, Safely::wrap(fn (CacheMissed $e) => $this->cache('miss', $e->key, $e->storeName ?? null)));
            $events->listen(KeyWritten::class, Safely::wrap(fn (KeyWritten $e) => $this->cache('write', $e->key, $e->storeName ?? null)));
            $events->listen(KeyForgotten::class, Safely::wrap(fn (KeyForgotten $e) => $this->cache('forget', $e->key, $e->storeName ?? null)));
        }

        if ($this->config['http'] ?? true) {
            $events->listen(ResponseReceived::class, Safely::wrap($this->responseReceived(...)));
            $events->listen(ConnectionFailed::class, Safely::wrap($this->connectionFailed(...)));
        }

        if ($this->config['mail'] ?? true) {
            $events->listen(MessageSent::class, Safely::wrap($this->messageSent(...)));
        }

        if ($this->config['notifications'] ?? true) {
            $events->listen(NotificationSent::class, Safely::wrap($this->notificationSent(...)));
        }

        if ($this->config['redis'] ?? false) {
            $events->listen(CommandExecuted::class, Safely::wrap($this->commandExecuted(...)));
        }
    }

    public function cache(string $operation, string $key, ?string $store): void
    {
        if (Str::is([...self::FRAMEWORK_KEYS, ...($this->config['ignore_cache_keys'] ?? [])], $key)) {
            return;
        }

        $this->span('cache', $operation, $key, attributes: ['store' => $store]);
    }

    public function responseReceived(ResponseReceived $event): void
    {
        $seconds = $event->response->transferStats?->getTransferTime();

        $this->span(
            'http',
            strtoupper($event->request->method()),
            $this->address($event->request->url()),
            $seconds !== null ? $seconds * 1000 : null,
            ['status' => $event->response->status()],
        );
    }

    public function connectionFailed(ConnectionFailed $event): void
    {
        $this->span('http', strtoupper($event->request->method()), $this->address($event->request->url()), attributes: ['status' => 0, 'failed' => true]);
    }

    public function messageSent(MessageSent $event): void
    {
        $mailable = $event->data['__laravel_mailable'] ?? null;

        // The class, and how many it went to; never the addresses or the subject.
        $this->span('mail', 'sent', is_string($mailable) ? $mailable : 'mail', attributes: [
            'recipients' => count($event->message->getTo()) + count($event->message->getCc()) + count($event->message->getBcc()),
        ]);
    }

    public function notificationSent(NotificationSent $event): void
    {
        $this->span('notification', (string) $event->channel, $event->notification::class);
    }

    public function commandExecuted(CommandExecuted $event): void
    {
        // The command and the first argument (usually the key), never the values.
        $key = $event->parameters[0] ?? null;

        $this->span('redis', strtoupper($event->command), is_scalar($key) ? (string) $key : $event->command, $event->time, ['connection' => $event->connectionName]);
    }

    /**
     * An outgoing call as the host and path it went to; the query string can hold secrets.
     */
    private function address(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['host'] ?? $url).(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function span(string $kind, string $name, string $target, ?float $durationMs = null, array $attributes = []): void
    {
        if (! $this->recorder->capturing()) {
            return;
        }

        $this->recorder->record(array_filter([
            'type' => 'span',
            'kind' => $kind,
            'name' => $name,
            'target' => mb_substr($target, 0, 255),
            'duration_ms' => $durationMs !== null ? round($durationMs, 3) : null,
            'attributes' => array_filter($attributes, fn ($value): bool => $value !== null) ?: null,
            // Laravel tells after the fact: the span started its duration ago.
            'timestamp' => microtime(true) - ($durationMs ?? 0) / 1000,
        ], fn ($value): bool => $value !== null));
    }
}
