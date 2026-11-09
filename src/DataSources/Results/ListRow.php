<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources\Results;

use Stringable;

/** One row of a ListResult. */
final readonly class ListRow
{
    public string $label;

    public int|float|string|null $value;

    public function __construct(string|Stringable $label, int|float|string|Stringable|null $value)
    {
        $this->label = Cell::text($label);
        $this->value = Cell::value($value);
    }
}
