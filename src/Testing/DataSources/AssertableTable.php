<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing\DataSources;

use Closure;
use ExeQue\Lumberjack\Testing\DataSources\Concerns\ComparesResults;

final class AssertableTable
{
    use ComparesResults;

    /**
     * @param  list<string>  $columns
     * @param  list<list<int|float|string|null>>  $rows
     */
    public function __construct(
        private readonly array $columns,
        private readonly array $rows,
    ) {
    }

    /**
     * @param  list<string>  $expected
     */
    public function hasColumns(array $expected): static
    {
        return $this->check($this->columns === $expected, 'Expected the columns '.$this->show($expected).', got '.$this->show($this->columns).'.');
    }

    public function hasRows(int $expected): static
    {
        return $this->check(count($this->rows) === $expected, 'Expected '.$expected.' rows, got '.count($this->rows).'.');
    }

    public function isEmpty(): static
    {
        return $this->hasRows(0);
    }

    /**
     * @param  list<int|float|string|null>  $expected
     */
    public function hasRow(array $expected): static
    {
        return $this->check($this->find($expected) !== null, 'Expected the row '.$this->show($expected).'.');
    }

    /**
     * @param  list<int|float|string|null>  $expected
     */
    public function missingRow(array $expected): static
    {
        return $this->check($this->find($expected) === null, 'Expected no row '.$this->show($expected).'.', $this->find($expected));
    }

    /**
     * The row at this position has these cells. A negative position counts from the end.
     *
     * @param  list<int|float|string|null>  $expected
     */
    public function rowIs(int $index, array $expected): static
    {
        $i = $this->positionOf($index, count($this->rows));

        return $this->check($this->matches($this->rows[$i], $expected), 'Expected '.$this->show($expected).' at position '.$index.'.', $i);
    }

    public function cellIs(int $index, string $column, int|float|string|null $expected): static
    {
        $i = $this->positionOf($index, count($this->rows));
        $cell = $this->rows[$i][$this->position($column)];

        return $this->check($this->same($cell, $expected), 'Expected '.$this->show($expected).' in "'.$column.'" at position '.$index.', got '.$this->show($cell).'.', $i);
    }

    /**
     * The first row where $column is $value has these cells, by column name.
     *
     * @param  array<string, int|float|string|null>  $cells
     */
    public function hasRowWhere(string $column, int|float|string|null $value, array $cells): static
    {
        $where = $this->position($column);

        foreach ($this->rows as $i => $row) {
            if ($this->same($row[$where], $value)) {
                foreach ($cells as $name => $expected) {
                    $this->check($this->same($row[$this->position((string) $name)], $expected), 'Expected '.$this->show($expected).' in "'.$name.'".', $i);
                }

                return $this;
            }
        }

        return $this->check(false, 'Expected a row where "'.$column.'" is '.$this->show($value).'.');
    }

    /**
     * @param  Closure(AssertableColumn): mixed  $assert
     */
    public function column(string $name, Closure $assert): static
    {
        $at = $this->position($name);
        $assert(new AssertableColumn($name, array_map(fn (array $row): int|float|string|null => $row[$at], $this->rows)));

        return $this;
    }

    /**
     * @param  Closure(array<string, int|float|string|null>): bool  $check
     */
    public function eachRow(Closure $check): static
    {
        foreach ($this->rows as $i => $row) {
            $this->check($check(array_combine($this->columns, $row)) === true, 'A row did not pass the check.', $i);
        }

        return $this;
    }

    /**
     * The whole table.
     *
     * @param  list<string>  $columns
     * @param  list<list<int|float|string|null>>  $rows
     */
    public function is(array $columns, array $rows): static
    {
        $this->hasColumns($columns)->hasRows(count($rows));

        foreach ($rows as $i => $row) {
            $this->check($this->matches($this->rows[$i], $row), 'Expected the row '.$this->show($row).' here.', $i);
        }

        return $this;
    }

    /**
     * @return list<list<int|float|string|null>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    protected function lines(): array
    {
        return array_map(fn (array $row): string => implode('  ', array_map($this->show(...), $row)), $this->rows);
    }

    private function position(string $column): int
    {
        $at = array_search($column, $this->columns, true);
        $this->check($at !== false, 'There is no column "'.$column.'". The columns are '.$this->show($this->columns).'.');

        return (int) $at;
    }

    /**
     * @param  list<int|float|string|null>  $expected
     */
    private function find(array $expected): ?int
    {
        foreach ($this->rows as $i => $row) {
            if ($this->matches($row, $expected)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  list<int|float|string|null>  $row
     * @param  list<int|float|string|null>  $expected
     */
    private function matches(array $row, array $expected): bool
    {
        return count($row) === count($expected) && ! array_any($row, fn (mixed $cell, int $i): bool => ! $this->same($cell, $expected[$i]));
    }
}
