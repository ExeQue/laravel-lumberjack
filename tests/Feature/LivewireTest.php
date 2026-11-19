<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use ExeQue\Lumberjack\Listeners\RecordsLivewire;
use ExeQue\Lumberjack\Recorder;
use Livewire\Livewire;
use Tests\Support\CallsLivewire;

covers(RecordsLivewire::class);

uses(CallsLivewire::class);

beforeEach(function () {
    if (! class_exists(Livewire::class)) {
        $this->markTestSkipped('livewire/livewire is not installed.');
    }
});

describe('a Livewire update', function () {
    it('is recorded and counted under the component and method it called', function () {
        $snapshots = $this->livewireSnapshots();
        $this->agent->payloads = [];

        $this->livewireUpdate([['snapshot' => $snapshots['counter'], 'calls' => [['method' => 'increment']]]])->assertOk();
        app(Recorder::class)->flush();

        $request = $this->sentEvents('request')[0];

        expect($request)->toMatchArray(['method' => 'POST', 'route' => 'livewire:counter@increment', 'kind' => 'livewire'])
            // Livewire 4 puts a prefix before it.
            ->and($request['route_name'])->toEndWith('livewire.update')
            ->and($request['context']['livewire'])->toBe(['components' => [['name' => 'counter', 'class' => Tests\Fixtures\Livewire\Counter::class, 'calls' => ['increment'], 'updates' => 0]], 'page' => '/dashboard'])
            ->and($this->sentEvents('request_counts')[0]['counts'][0])->toMatchArray(['route' => 'livewire:counter@increment', 'kind' => 'livewire']);
    });

    it('puts each call and render on the trace as a span', function () {
        $snapshots = $this->livewireSnapshots();
        $this->agent->payloads = [];

        $this->livewireUpdate([['snapshot' => $snapshots['counter'], 'calls' => [['method' => 'increment']]]]);

        $spans = collect($this->sentEvents('span'))->where('kind', 'livewire');

        expect($spans->map(fn (array $span): string => $span['name'].' '.$span['target'])->values()->all())->toBe(['call counter@increment', 'render counter'])
            ->and($spans->first()['duration_ms'])->toBeFloat();
    });

    it('names a route for what the update did', function (Closure $components, string $route) {
        // Livewire 3 sends $refresh as a commit without a call.
        if ($route === 'livewire:counter@$refresh' && version_compare((string) InstalledVersions::getVersion('livewire/livewire'), '4.0.0', '<')) {
            $this->markTestSkipped('Livewire 3 sends no $refresh call.');
        }

        $snapshots = $this->livewireSnapshots();
        $this->agent->payloads = [];

        $this->livewireUpdate($components($snapshots))->assertOk();

        expect($this->sentEvents('request')[0]['route'])->toBe($route);
    })->with([
        'a property update without a call' => [fn (array $s): array => [['snapshot' => $s['counter'], 'updates' => ['count' => 5]]], 'livewire:counter@(update)'],
        'a poll' => [fn (array $s): array => [['snapshot' => $s['counter'], 'calls' => [['method' => '$refresh']]]], 'livewire:counter@$refresh'],
        'an event the component listens to' => [fn (array $s): array => [['snapshot' => $s['counter'], 'calls' => [['method' => '__dispatch', 'params' => ['reset-counter', []]]]]], 'livewire:counter@__dispatch:reset-counter'],
        'two methods of one component' => [fn (array $s): array => [['snapshot' => $s['counter'], 'calls' => [['method' => 'increment'], ['method' => 'resetCount']]]], 'livewire:counter@(batch)'],
        'several components at once' => [fn (array $s): array => [['snapshot' => $s['counter'], 'calls' => [['method' => 'increment']]], ['snapshot' => $s['greeting'], 'updates' => ['name' => 'you']]], 'livewire:(batch)'],
    ]);

    it('counts the property updates of each component', function () {
        $snapshots = $this->livewireSnapshots();
        $this->agent->payloads = [];

        $this->livewireUpdate([['snapshot' => $snapshots['greeting'], 'updates' => ['name' => 'you']]]);

        expect($this->sentEvents('request')[0]['context']['livewire']['components'][0])->toMatchArray(['name' => 'greeting', 'calls' => [], 'updates' => 1]);
    });

    it('never names a route for a method the component does not have', function () {
        $snapshots = $this->livewireSnapshots();
        $this->agent->payloads = [];

        $this->livewireUpdate([['snapshot' => $snapshots['counter'], 'calls' => [['method' => 'notAMethod']]]]);

        expect($this->sentEvents('request')[0]['route'])->toBe('livewire:counter@(update)')
            ->and($this->sentEvents('request')[0]['context']['livewire']['components'][0]['calls'])->toBe([])
            ->and(collect($this->sentEvents('span'))->pluck('target')->all())->not->toContain('counter@notAMethod');
    });

    it('hands the method\'s return value back to the browser untouched', function () {
        $snapshots = $this->livewireSnapshots();

        $this->livewireUpdate([['snapshot' => $snapshots['counter'], 'updates' => ['count' => 21], 'calls' => [['method' => 'doubled']]]])
            ->assertOk()
            ->assertJsonPath('components.0.effects.returns.0', 42);
    });

    it('leaves the app\'s exception alone and counts no call for a method that threw', function () {
        $this->withoutExceptionHandling();
        $snapshots = $this->livewireSnapshots();
        $this->agent->payloads = [];

        expect(fn () => $this->livewireUpdate([['snapshot' => $snapshots['counter'], 'calls' => [['method' => 'explode']]]]))
            ->toThrow(RuntimeException::class, 'The counter broke.');

        expect(collect($this->sentEvents('span'))->pluck('target')->all())->not->toContain('counter@explode');
    });

    it('starts each update afresh, as a long-lived worker serves one after another', function () {
        $snapshots = $this->livewireSnapshots();
        $this->agent->payloads = [];

        $this->livewireUpdate([['snapshot' => $snapshots['counter'], 'calls' => [['method' => 'increment']]]]);
        $this->livewireUpdate([['snapshot' => $snapshots['greeting'], 'updates' => ['name' => 'you']]]);

        $second = $this->sentEvents('request')[1];

        expect($second['route'])->toBe('livewire:greeting@(update)')
            ->and(array_column($second['context']['livewire']['components'], 'name'))->toBe(['greeting']);
    });

    it('leaves the page that rendered the components an ordinary request', function () {
        $this->livewireSnapshots();
        app(Recorder::class)->flush();

        expect($this->sentEvents('request')[0])->toMatchArray(['route' => '/dashboard'])
            ->and($this->sentEvents('request')[0])->not->toHaveKey('kind');
    });
});

describe('turned off', function () {
    it('records a Livewire update under Livewire\'s own route', function () {
        $this->withConfig(['lumberjack.requests.livewire' => false]);
        $snapshots = $this->livewireSnapshots();
        $this->agent->payloads = [];

        $this->livewireUpdate([['snapshot' => $snapshots['counter'], 'calls' => [['method' => 'increment']]]]);

        expect($this->sentEvents('request')[0]['route_name'])->toEndWith('livewire.update')
            ->and($this->sentEvents('request')[0]['route'])->not->toStartWith('livewire:')
            ->and($this->sentEvents('request')[0])->not->toHaveKey('kind')
            ->and(collect($this->sentEvents('span'))->where('kind', 'livewire'))->toBeEmpty();
    });
});
