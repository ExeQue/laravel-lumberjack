<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources;

use Closure;
use ExeQue\Lumberjack\Contracts\DataSource;
use ExeQue\Lumberjack\Contracts\DataSourceResult;

/**
 * A source without a class of its own, from DataSourceRegistry::define().
 */
final readonly class CallbackDataSource implements DataSource
{
    /**
     * @param  Closure(DataRange): DataSourceResult  $resolver
     */
    public function __construct(
        private string $key,
        private string $label,
        public Shape $shape,
        private Closure $resolver,
        public ?int $cacheSeconds = null,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function resolve(DataRange $range): DataSourceResult
    {
        return ($this->resolver)($range);
    }
}
