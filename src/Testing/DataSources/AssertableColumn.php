<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing\DataSources;

use ExeQue\Lumberjack\Testing\DataSources\Concerns\ComparesResults;
use ExeQue\Lumberjack\Testing\DataSources\Concerns\SumsNumbers;

final class AssertableColumn
{
    use ComparesResults;
    use SumsNumbers;

    /**
     * @param  list<int|float|string|null>  $values
     */
    public function __construct(
        private readonly string $name,
        private readonly array $values,
    ) {
    }

    public function isNumeric(): static
    {
        foreach ($this->values as $i => $value) {
            $this->check(is_int($value) || is_float($value), 'Expected only numbers in "'.$this->name.'".', $i);
        }

        return $this;
    }

    public function isSorted(bool $descending = false): static
    {
        $wrong = $this->outOfOrder($this->values, $descending);

        return $this->check($wrong === null, 'Expected "'.$this->name.'" sorted.', $wrong);
    }

    public function contains(int|float|string|null $expected): static
    {
        return $this->check(array_any($this->values, fn (mixed $value): bool => $this->same($value, $expected)), 'Expected '.$this->show($expected).' in "'.$this->name.'".');
    }

    public function isUnique(): static
    {
        $seen = [];

        foreach ($this->values as $i => $value) {
            $this->check(! array_any($seen, fn (mixed $earlier): bool => $this->same($earlier, $value)), 'Expected every value in "'.$this->name.'" once.', $i);
            $seen[] = $value;
        }

        return $this;
    }

    /**
     * @return list<int|float|string|null>
     */
    public function values(): array
    {
        return $this->values;
    }

    protected function lines(): array
    {
        return array_map($this->show(...), $this->values);
    }

    protected function numbers(): array
    {
        return array_values(array_filter($this->values, fn (mixed $value): bool => is_int($value) || is_float($value)));
    }
}
