<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Listeners;

use Closure;
use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Support\Safely;

/**
 * Tells what a Livewire update did through Livewire's own hooks (3 and 4): which
 * components it hydrated, the methods they ran and the properties it updated. They fire
 * after Livewire checked the snapshot's checksum, and a call counts only once its method
 * ran, so the names come from the app and never from what the browser sent.
 *
 * Each call and render is a "livewire" span on the trace. The request itself gets a
 * route of its own: the component and method, instead of Livewire's one update route.
 */
class RecordsLivewire
{
    /** Components and calls kept per request; a request past this is cut short. */
    public const int MAX_ITEMS = 50;

    public const int MAX_PAGE = 255;

    /** @var array<string, array{name: string, class: string, calls: list<string>, updates: int}> by component id */
    private array $components = [];

    private ?string $page = null;

    public function __construct(
        private readonly Recorder $recorder,
    ) {
    }

    /** Whether Livewire is installed to listen to. */
    public static function available(): bool
    {
        return function_exists('Livewire\\on');
    }

    public function listen(): void
    {
        \Livewire\on('hydrate', Safely::wrap($this->hydrated(...)));
        \Livewire\on('update', Safely::wrap($this->updated(...)));
        // These two answer with what Livewire runs once the call or render is done.
        \Livewire\on('call', fn (mixed ...$arguments) => Safely::value($this->called(...), ...$arguments));
        \Livewire\on('render', fn (mixed ...$arguments) => Safely::value($this->rendering(...), ...$arguments));
    }

    /** A new request: what the last one left is forgotten. */
    public function reset(): void
    {
        $this->components = [];
        $this->page = null;
    }

    /** Whether the current request was a Livewire update: it hydrated a component. */
    public function handled(): bool
    {
        return $this->components !== [];
    }

    /**
     * The route the request is counted under: livewire:name@method, @(update) for a
     * property update without a call, livewire:(batch) for several components at once.
     */
    public function route(): ?string
    {
        if ($this->components === []) {
            return null;
        }

        if (count($this->components) > 1) {
            return 'livewire:(batch)';
        }

        $component = reset($this->components);
        $calls = array_values(array_unique($component['calls']));

        return 'livewire:'.$component['name'].'@'.match (count($calls)) {
            0 => '(update)',
            1 => $calls[0],
            default => '(batch)',
        };
    }

    /**
     * @return array{components: list<array{name: string, class: string, calls: list<string>, updates: int}>, page: string|null}|null
     */
    public function context(): ?array
    {
        return $this->components === [] ? null : ['components' => array_values($this->components), 'page' => $this->page];
    }

    /**
     * @param  array<string, mixed>  $memo
     */
    public function hydrated(object $component, array $memo = []): void
    {
        if (count($this->components) >= self::MAX_ITEMS) {
            return;
        }

        $this->components[$this->id($component)] ??= ['name' => $this->name($component), 'class' => $component::class, 'calls' => [], 'updates' => 0];
        $this->page ??= is_string($memo['path'] ?? null) ? mb_substr('/'.ltrim($memo['path'], '/'), 0, self::MAX_PAGE) : null;
    }

    public function updated(object $component): void
    {
        if (isset($this->components[$id = $this->id($component)])) {
            $this->components[$id]['updates']++;
        }
    }

    /**
     * Times the call and returns what Livewire runs once the method has run: only then is
     * it counted, so a method the component does not have never makes a route.
     */
    public function called(object $component, mixed $method, mixed $params = []): ?Closure
    {
        if (! is_string($method)) {
            return null;
        }

        // An event a component listens to is a call of __dispatch: named for the event.
        if ($method === '__dispatch' && is_array($params) && is_string($params[0] ?? null)) {
            $method = '__dispatch:'.$params[0];
        }

        $start = microtime(true);

        return function () use ($component, $method, $start): void {
            Safely::run(function () use ($component, $method, $start): void {
                $id = $this->id($component);

                if (isset($this->components[$id]) && count($this->components[$id]['calls']) < self::MAX_ITEMS) {
                    $this->components[$id]['calls'][] = $method;
                }

                $this->span('call', $this->name($component).'@'.$method, $start);
            });
        };
    }

    public function rendering(object $component): ?Closure
    {
        $start = microtime(true);

        return function () use ($component, $start): void {
            Safely::run(fn () => $this->span('render', $this->name($component), $start));
        };
    }

    private function span(string $name, string $target, float $start): void
    {
        if (! $this->recorder->capturing()) {
            return;
        }

        $this->recorder->record([
            'type' => 'span',
            'kind' => 'livewire',
            'name' => $name,
            'target' => mb_substr($target, 0, 255),
            'duration_ms' => round((microtime(true) - $start) * 1000, 3),
            'timestamp' => $start,
        ]);
    }

    private function id(object $component): string
    {
        return method_exists($component, 'getId') ? (string) $component->getId() : (string) spl_object_id($component);
    }

    private function name(object $component): string
    {
        return method_exists($component, 'getName') ? (string) $component->getName() : $component::class;
    }
}
