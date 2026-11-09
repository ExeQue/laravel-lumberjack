<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing\Assertable\Concerns;

use Closure;
use Illuminate\Support\Arr;

/**
 * Checks on a recorded array, by key with dot notation.
 */
trait ChecksFields
{
    use CollectsChecks;

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /** The key is there; with a value given, it has that value. */
    public function has(string $key, mixed $value = null): static
    {
        $exists = Arr::has($this->toArray(), $key);
        $actual = data_get($this->toArray(), $key);

        return func_num_args() === 1 || ! $exists
            ? $this->check($key, $exists ? $actual : '(missing)', $exists, func_num_args() === 1 ? 'it to be there' : json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR).'')
            : $this->compare($key, $actual, $value);
    }

    public function missing(string $key): static
    {
        return $this->check($key, data_get($this->toArray(), $key), ! Arr::has($this->toArray(), $key), 'it not to be there');
    }

    /**
     * @param  Closure(mixed): bool  $check
     */
    public function where(string $key, Closure $check): static
    {
        $actual = data_get($this->toArray(), $key);

        return $this->check($key, Arr::has($this->toArray(), $key) ? $actual : '(missing)', Arr::has($this->toArray(), $key) && $check($actual) === true, 'it to pass the check');
    }
}
