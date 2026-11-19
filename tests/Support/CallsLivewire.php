<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Fixtures\Livewire\Counter;
use Tests\Fixtures\Livewire\Greeting;

/**
 * Livewire updates over HTTP, as the browser sends them: the snapshot a page rendered,
 * with the property updates and calls of each component.
 */
trait CallsLivewire
{
    /**
     * The snapshots of the components on a page with a counter and a greeting.
     *
     * @return array{counter: string, greeting: string}
     */
    protected function livewireSnapshots(): array
    {
        Livewire::component('counter', Counter::class);
        Livewire::component('greeting', Greeting::class);
        Route::get('/dashboard', fn () => Blade::render('<livewire:counter /><livewire:greeting />'))->middleware('web');

        preg_match_all('/wire:snapshot="([^"]+)"/', (string) $this->get('/dashboard')->getContent(), $matches);
        [$counter, $greeting] = array_map(fn (string $snapshot): string => html_entity_decode($snapshot, ENT_QUOTES), $matches[1]);

        return ['counter' => $counter, 'greeting' => $greeting];
    }

    /**
     * @param  list<array{snapshot: string, updates?: array<string, mixed>, calls?: list<array{method: string, params?: list<mixed>}>}>  $components
     */
    protected function livewireUpdate(array $components): TestResponse
    {
        return $this->postJson(app('livewire')->getUpdateUri(), ['components' => array_map(fn (array $component): array => [
            'snapshot' => $component['snapshot'],
            'updates' => $component['updates'] ?? [],
            'calls' => array_map(fn (array $call): array => ['method' => $call['method'], 'params' => $call['params'] ?? [], 'metadata' => []], $component['calls'] ?? []),
        ], $components)], ['X-Livewire' => '1']);
    }
}
