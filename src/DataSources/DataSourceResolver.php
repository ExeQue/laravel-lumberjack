<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources;

use ExeQue\Lumberjack\Contracts\DataSource;
use ExeQue\Lumberjack\DataSources\Results\Cell;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Debug\ExceptionHandler;
use ReflectionClass;
use Throwable;

/**
 * Answers for each source on its own, so one that fails costs only its own entry. The
 * reason stays in the app's error reporting: the answer only says that it failed.
 */
final readonly class DataSourceResolver
{
    public const string ERROR_UNKNOWN = 'unknown source';

    public const string ERROR_FAILED = 'failed';

    public function __construct(
        private DataSourceRegistry $registry,
        private CacheFactory $cache,
        private ExceptionHandler $exceptions,
    ) {
    }

    /**
     * What the app offers. complete is false when a source could not be made or described:
     * then what is missing from the list says nothing, and Lumberjack archives none of it.
     *
     * @return array{sources: list<array{key: string, label: string, shape: string, cache: int|null}>, complete: bool}
     */
    public function index(): array
    {
        $complete = $this->registry->isComplete();
        $sources = [];

        foreach ($this->registry->all() as $source) {
            try {
                $sources[] = ['key' => $source->key(), 'label' => Cell::text($source->label()), 'shape' => Shape::of($source)->value, 'cache' => self::cacheSeconds($source)];
            } catch (Throwable $e) {
                $complete = false;
                $this->exceptions->report($e);
            }
        }

        return ['sources' => $sources, 'complete' => $complete];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, array{shape: string, data: mixed}|array{error: string}>
     */
    public function resolve(array $keys, DataRange $range): array
    {
        $sources = $this->registry->all();
        $answers = [];

        foreach ($keys as $key) {
            $answers[$key] = isset($sources[$key]) ? $this->answer($sources[$key], $range) : ['error' => self::ERROR_UNKNOWN];
        }

        return $answers;
    }

    /**
     * @return array{shape: string, data: mixed}|array{error: string}
     */
    private function answer(DataSource $source, DataRange $range): array
    {
        try {
            return ['shape' => Shape::of($source)->value, 'data' => $this->data($source, $range)];
        } catch (Throwable $e) {
            $this->exceptions->report($e);

            return ['error' => self::ERROR_FAILED];
        }
    }

    private function data(DataSource $source, DataRange $range): mixed
    {
        $seconds = self::cacheSeconds($source);
        $resolve = fn (): mixed => $source->resolve($range)->data();

        if ($seconds === null) {
            return $resolve();
        }

        // Wrapped, as a cache cannot tell a stored null from nothing stored.
        $key = 'lumberjack:data-source:'.$source->key().':'.Shape::of($source)->value.':'.$range->from->getTimestamp().':'.$range->to->getTimestamp().':'.$range->bucket.':'.$range->timezone;

        return $this->cache->store()->remember($key, $seconds, fn (): array => ['data' => $resolve()])['data'];
    }

    /** What the source declares, by #[CacheFor] on it or a class it extends, or define()'s cacheFor. */
    public static function cacheSeconds(DataSource $source): ?int
    {
        if ($source instanceof CallbackDataSource) {
            return $source->cacheSeconds;
        }

        for ($class = new ReflectionClass($source); $class !== false; $class = $class->getParentClass()) {
            $attribute = $class->getAttributes(CacheFor::class)[0] ?? null;

            if ($attribute !== null) {
                return $attribute->newInstance()->seconds;
            }
        }

        return null;
    }
}
