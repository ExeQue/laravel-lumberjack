<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources;

use Closure;
use ExeQue\Lumberjack\Contracts\DataSource;
use ExeQue\Lumberjack\Contracts\DataSourceResult;
use ExeQue\Lumberjack\Exceptions\InvalidDataSourceException;
use ExeQue\Lumberjack\Support\Safely;
use Illuminate\Contracts\Container\Container;
use ReflectionFunction;
use Throwable;

/**
 * The data sources from config and from Lumberjack::dataSources(). A class is kept as given
 * and only made by the container when it is first needed, so registering one costs a
 * request nothing and it can take its dependencies in its constructor.
 */
final class DataSourceRegistry
{
    public const string KEY_PATTERN = '/^[a-z0-9][a-z0-9:._-]{0,99}$/D';

    /** @var list<DataSource|class-string<DataSource>> */
    private array $entries = [];

    /** @var array<string, DataSource>|null */
    private ?array $made = null;

    private bool $refused = false;

    /**
     * @param  array<mixed>  $entries  each a class or a DataSource
     */
    public function __construct(
        private readonly Container $container,
        array $entries = [],
    ) {
        // From config: a bad entry is reported and skipped, never thrown into the app's boot.
        foreach ($entries as $entry) {
            $this->guarded(fn () => $this->add($entry), 'lumberjack.data_sources.sources');
        }
    }

    /**
     * Each argument is a DataSource, or a class the container makes when it is needed. A
     * bad one throws in local and testing; elsewhere it is reported and left out, as this
     * runs in the app's boot and must never take the app down.
     *
     * @param  mixed  ...$sources  DataSource instances or class names; config values can be anything
     */
    public function add(mixed ...$sources): self
    {
        foreach ($sources as $source) {
            $this->guarded(function () use ($source): void {
                if (! $source instanceof DataSource && ! (is_string($source) && is_subclass_of($source, DataSource::class))) {
                    throw InvalidDataSourceException::notADataSource(is_string($source) ? $source : get_debug_type($source));
                }

                Shape::of($source);

                if ($source instanceof DataSource) {
                    self::validateKey($source->key());
                }

                $this->entries[] = $source;
                $this->made = null;
            });
        }

        return $this;
    }

    /**
     * A source without a class of its own. $resolver gets the DataRange and declares the
     * result class it returns, as DataSource::resolve() does; $cacheFor keeps that per
     * range, like #[CacheFor]. Untyped, as add() is, so a wrong value is refused like any
     * bad source rather than taking the app down from a service provider.
     *
     * @param  mixed  $key  string
     * @param  mixed  $label  string
     * @param  mixed  $resolver  callable taking a DataRange and returning a DataSourceResult
     * @param  mixed  $cacheFor  seconds, or null
     */
    public function define(mixed $key, mixed $label, mixed $resolver, mixed $cacheFor = null): self
    {
        $this->guarded(function () use ($key, $label, $resolver, $cacheFor): void {
            if (! is_string($key) || ! is_string($label) || ! is_callable($resolver) || ! (is_int($cacheFor) || $cacheFor === null)) {
                throw InvalidDataSourceException::notADataSource(is_string($key) ? $key : get_debug_type($key));
            }

            $closure = Closure::fromCallable($resolver);
            $this->add(new CallbackDataSource($key, $label, Shape::returnedBy(new ReflectionFunction($closure)), $closure, $cacheFor));
        });

        return $this;
    }

    /** False when a source was refused or could not be made: then all() is not everything the app offers. */
    public function isComplete(): bool
    {
        $this->all();

        return $this->made !== null && ! $this->refused;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * The sources by key. One that cannot be made, has a bad key or repeats a key is
     * reported and left out, so it never costs the others.
     *
     * @return array<string, DataSource>
     */
    public function all(): array
    {
        if ($this->made !== null) {
            return $this->made;
        }

        $made = [];
        $complete = true;

        foreach ($this->entries as $entry) {
            try {
                $source = is_string($entry) ? $this->container->make($entry) : $entry;
                $key = self::validateKey($source->key());

                if (isset($made[$key])) {
                    throw InvalidDataSourceException::duplicateKey($key);
                }

                $made[$key] = $source;
            } catch (Throwable $e) {
                $complete = false;
                Safely::problem('Lumberjack skipped a data source: '.$e->getMessage());
            }
        }

        // What failed is tried again next time: a worker must not lose it for its lifetime.
        return $complete ? $this->made = $made : $made;
    }

    private function guarded(Closure $register, ?string $from = null): void
    {
        try {
            $register();
        } catch (Throwable $e) {
            if ($from === null && $this->container->bound('env') && in_array($this->container->make('env'), ['local', 'testing'], true)) {
                throw $e;
            }

            $this->refused = true;
            Safely::problem('Lumberjack skipped a data source'.($from !== null ? " in {$from}" : '').': '.$e->getMessage());
        }
    }

    private static function validateKey(string $key): string
    {
        return preg_match(self::KEY_PATTERN, $key) === 1 ? $key : throw InvalidDataSourceException::badKey($key);
    }
}
