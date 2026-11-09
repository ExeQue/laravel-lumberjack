<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources\Results;

use ExeQue\Lumberjack\Contracts\DataSourceResult;
use ExeQue\Lumberjack\DataSources\Shape;
use ExeQue\Lumberjack\Exceptions\InvalidDataSourceResultException;

/** Labelled rows, at most Shape::MAX_ROWS. Shown on a list. */
final readonly class ListResult implements DataSourceResult
{
    /** @var list<ListRow> */
    public array $rows;

    public function __construct(ListRow ...$rows)
    {
        if (count($rows) > Shape::MAX_ROWS) {
            throw new InvalidDataSourceResultException('Expected at most '.Shape::MAX_ROWS.' rows.');
        }

        $this->rows = array_values($rows);
    }

    public static function shape(): Shape
    {
        return Shape::List;
    }

    /**
     * @return list<array{label: string, value: int|float|string|null}>
     */
    public function data(): array
    {
        return array_map(fn (ListRow $row): array => ['label' => $row->label, 'value' => $row->value], $this->rows);
    }
}
