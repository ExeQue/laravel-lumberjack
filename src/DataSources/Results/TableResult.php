<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources\Results;

use ExeQue\Lumberjack\Contracts\DataSourceResult;
use ExeQue\Lumberjack\DataSources\Shape;
use ExeQue\Lumberjack\Exceptions\InvalidDataSourceResultException;

/** Named columns and rows of cells. On a list, the first column is the label and the second the value. */
final readonly class TableResult implements DataSourceResult
{
    /** @var list<string> */
    public array $columns;

    /** @var list<list<int|float|string|null>> */
    public array $rows;

    /**
     * @param  array<array-key, mixed>  $columns  at most Shape::MAX_COLUMNS
     * @param  array<array-key, mixed>  $rows  at most Shape::MAX_ROWS, one cell per column in each
     *
     * @throws InvalidDataSourceResultException
     */
    public function __construct(array $columns, array $rows)
    {
        if (count($columns) > Shape::MAX_COLUMNS || count($rows) > Shape::MAX_ROWS) {
            throw new InvalidDataSourceResultException('Expected at most '.Shape::MAX_COLUMNS.' columns and '.Shape::MAX_ROWS.' rows.');
        }

        $this->columns = array_map(Cell::text(...), array_values($columns));
        $this->rows = array_map(function (mixed $row) use ($columns): array {
            if (! is_array($row) || ! array_is_list($row) || count($row) !== count($columns)) {
                throw new InvalidDataSourceResultException('Expected every row to be a list of one cell per column.');
            }

            return array_map(Cell::value(...), $row);
        }, array_values($rows));
    }

    public static function shape(): Shape
    {
        return Shape::Table;
    }

    /**
     * @return array{columns: list<string>, rows: list<list<int|float|string|null>>}
     */
    public function data(): array
    {
        return ['columns' => $this->columns, 'rows' => $this->rows];
    }
}
