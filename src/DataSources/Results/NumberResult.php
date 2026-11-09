<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources\Results;

use ExeQue\Lumberjack\Contracts\DataSourceResult;
use ExeQue\Lumberjack\DataSources\Shape;

/** One figure, or null for none. Shown on a stat or a gauge. */
final readonly class NumberResult implements DataSourceResult
{
    public int|float|null $value;

    public function __construct(int|float|null $value)
    {
        $this->value = $value === null ? null : Cell::number($value);
    }

    public static function shape(): Shape
    {
        return Shape::Number;
    }

    public function data(): int|float|null
    {
        return $this->value;
    }
}
