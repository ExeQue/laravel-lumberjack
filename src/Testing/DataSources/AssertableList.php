<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing\DataSources;

use Closure;
use ExeQue\Lumberjack\DataSources\Results\ListRow;
use ExeQue\Lumberjack\Testing\DataSources\Concerns\ComparesResults;
use ExeQue\Lumberjack\Testing\DataSources\Concerns\SumsNumbers;

final class AssertableList
{
    use ComparesResults;
    use SumsNumbers;

    /**
     * @param  list<ListRow>  $rows
     */
    public function __construct(
        private readonly array $rows,
    ) {
    }

    public function hasRows(int $expected): static
    {
        return $this->check(count($this->rows) === $expected, 'Expected '.$expected.' rows, got '.count($this->rows).'.');
    }

    public function isEmpty(): static
    {
        return $this->hasRows(0);
    }

    public function hasRow(string $label, int|float|string|null $value): static
    {
        foreach ($this->rows as $row) {
            if ($row->label === $label && $this->same($row->value, $value)) {
                return $this->check(true, '');
            }
        }

        return $this->check(false, 'Expected a row "'.$label.'" with '.$this->show($value).'.');
    }

    /** The row at this position has this label and value. A negative position counts from the end. */
    public function rowIs(int $index, string $label, int|float|string|null $value): static
    {
        return $this->labelIs($index, $label)->valueIs($index, $value);
    }

    public function labelIs(int $index, string $label): static
    {
        $i = $this->positionOf($index, count($this->rows));

        return $this->check($this->rows[$i]->label === $label, 'Expected "'.$label.'" at position '.$index.', got "'.$this->rows[$i]->label.'".', $i);
    }

    public function valueIs(int $index, int|float|string|null $value): static
    {
        $i = $this->positionOf($index, count($this->rows));

        return $this->check($this->same($this->rows[$i]->value, $value), 'Expected '.$this->show($value).' at position '.$index.', got '.$this->show($this->rows[$i]->value).'.', $i);
    }

    public function hasLabel(string $label): static
    {
        return $this->check(in_array($label, $this->labels(), true), 'Expected a row "'.$label.'".');
    }

    public function missingLabel(string $label): static
    {
        $found = array_search($label, $this->labels(), true);

        return $this->check($found === false, 'Expected no row "'.$label.'".', $found === false ? null : $found);
    }

    /**
     * @param  list<string>  $labels
     */
    public function hasLabels(array $labels): static
    {
        foreach ($labels as $label) {
            $this->hasLabel($label);
        }

        return $this;
    }

    public function startsWith(string $label): static
    {
        return $this->check(($this->labels()[0] ?? null) === $label, 'Expected the first row to be "'.$label.'".', 0);
    }

    public function endsWith(string $label): static
    {
        $last = count($this->rows) - 1;

        return $this->check(($this->labels()[$last] ?? null) === $label, 'Expected the last row to be "'.$label.'".', $last);
    }

    public function isSortedByValue(bool $descending = false): static
    {
        $wrong = $this->outOfOrder(array_map(fn (ListRow $row): int|float|string|null => $row->value, $this->rows), $descending);

        return $this->check($wrong === null, 'Expected the rows sorted by value.', $wrong);
    }

    public function isSortedByLabel(bool $descending = false): static
    {
        $wrong = $this->outOfOrder($this->labels(), $descending);

        return $this->check($wrong === null, 'Expected the rows sorted by label.', $wrong);
    }

    /**
     * The whole list in order, as label => value. Use hasRow() for labels that repeat.
     *
     * @param  array<int|string, int|float|string|null>  $expected
     */
    public function is(array $expected): static
    {
        $this->hasRows(count($expected));

        foreach (array_keys($expected) as $i => $label) {
            $this->check($this->rows[$i]->label === (string) $label && $this->same($this->rows[$i]->value, $expected[$label]), 'Expected "'.$label.'" with '.$this->show($expected[$label]).' here.', $i);
        }

        return $this;
    }

    /**
     * @param  Closure(ListRow): bool  $check
     */
    public function each(Closure $check): static
    {
        foreach ($this->rows as $i => $row) {
            $this->check($check($row) === true, 'A row did not pass the check.', $i);
        }

        return $this;
    }

    /**
     * @return list<ListRow>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    protected function lines(): array
    {
        return array_map(fn (ListRow $row): string => $row->label.'  '.$this->show($row->value), $this->rows);
    }

    protected function numbers(): array
    {
        return array_values(array_filter(array_map(fn (ListRow $row): int|float|string|null => $row->value, $this->rows), fn (mixed $value): bool => is_int($value) || is_float($value)));
    }

    /**
     * @return list<string>
     */
    private function labels(): array
    {
        return array_map(fn (ListRow $row): string => $row->label, $this->rows);
    }
}
