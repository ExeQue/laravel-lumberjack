<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Contracts;

use ExeQue\Lumberjack\DataSources\Shape;

/**
 * What a data source answers with. Its class is what says the shape, so the return type
 * of resolve() tells Lumberjack which widgets can show it without running anything.
 */
interface DataSourceResult
{
    public static function shape(): Shape;

    /** The answer as it goes over the wire. */
    public function data(): mixed;
}
