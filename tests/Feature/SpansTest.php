<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Listeners\RecordsSpans;
use ExeQue\Lumberjack\Recorder;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as Notifications;

covers(RecordsSpans::class);

beforeEach(function () {
    app(Recorder::class)->capture();
});

describe('spans', function () {
    it('records cache reads and writes, but not the framework\'s own keys', function () {
        Cache::put('cart:7', 'x');
        Cache::get('cart:7');
        Cache::get('cart:8');
        Cache::forget('cart:7');
        Cache::put('illuminate:queue:restart', 1);
        app(Recorder::class)->flush();

        $spans = collect($this->sentEvents('span'))->where('kind', 'cache');
        expect($spans->map(fn (array $s): string => $s['name'].' '.$s['target'])->values()->all())
            ->toBe(['write cart:7', 'hit cart:7', 'miss cart:8', 'forget cart:7']);
    });

    it('records an outgoing call as its host and path, without the query string, with the status', function () {
        Http::fake(['api.stripe.com/*' => Http::response(['ok' => true], 201)]);

        Http::post('https://api.stripe.com/v1/charges?api_key=secret', ['amount' => 100]);
        app(Recorder::class)->flush();

        $span = collect($this->sentEvents('span'))->firstWhere('kind', 'http');
        expect($span)->toMatchArray(['name' => 'POST', 'target' => 'api.stripe.com/v1/charges'])
            ->and($span['attributes']['status'])->toBe(201)
            ->and(json_encode($span))->not->toContain('secret');
    });

    it('records a notification sent, by its class and channel', function () {
        $notification = new class () extends Notification {
            /** @return list<string> */
            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): Illuminate\Notifications\Messages\MailMessage
            {
                return (new Illuminate\Notifications\Messages\MailMessage())->line('Hi');
            }
        };

        Notifications::route('mail', 'someone@example.test')->notify($notification);
        app(Recorder::class)->flush();

        $kinds = collect($this->sentEvents('span'))->pluck('kind')->all();
        expect($kinds)->toContain('notification')->toContain('mail');
        expect(json_encode($this->sentEvents('span')))->not->toContain('someone@example.test');
    });

    it('can be turned off per kind', function () {
        $this->withConfig(['lumberjack.spans.cache' => false]);
        app(Recorder::class)->capture();

        Cache::get('cart:1');
        app(Recorder::class)->flush();

        expect(collect($this->sentEvents('span'))->where('kind', 'cache'))->toBeEmpty();
    });
});

it('ignores cache keys outside a run before matching any pattern', function () {
    app(Recorder::class)->capture(false);
    cache()->put('orders:1', 'x');
    cache()->get('orders:1');
    app(ExeQue\Lumberjack\Recorder::class)->flush();

    expect($this->sentEvents('span'))->toBe([]);
});
