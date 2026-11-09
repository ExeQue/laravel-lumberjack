<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing\DataSources\Concerns;

/**
 * Sums over one list of values: a series, a list or a table's column, but not a whole table.
 */
trait SumsNumbers
{
    /** @return list<int|float> the numbers to sum, leaving out what is not one */
    abstract protected function numbers(): array;

    public function sumsTo(int|float $expected): static
    {
        return $this->check($this->same(array_sum($this->numbers()), $expected), 'Expected a sum of '.$expected.', got '.array_sum($this->numbers()).'.');
    }

    public function maxIs(int|float $expected): static
    {
        $max = $this->numbers() === [] ? null : max($this->numbers());

        return $this->check($this->same($max, $expected), 'Expected a maximum of '.$expected.', got '.$this->show($max).'.');
    }

    public function minIs(int|float $expected): static
    {
        $min = $this->numbers() === [] ? null : min($this->numbers());

        return $this->check($this->same($min, $expected), 'Expected a minimum of '.$expected.', got '.$this->show($min).'.');
    }
}
