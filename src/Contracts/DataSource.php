<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Contracts;

use ExeQue\Lumberjack\DataSources\DataRange;

/**
 * A figure, series, list or table the app offers Lumberjack's status screens.
 *
 * Add #[CacheFor(seconds: 300)] to the class to keep what resolve() gives for each range,
 * and #[Group('Events', 'Storage')] to file it under Events > Storage in Lumberjack's picker.
 */
interface DataSource
{
    /**
     * What Lumberjack knows it by. Keep it stable: 1 to 100 of a-z, 0-9, ":", ".", "_" and
     * "-", starting with a letter or digit.
     */
    public function key(): string;

    /** What people pick it by in Lumberjack. */
    public function label(): string;

    /**
     * The data for this range. Declare the result class you return (NumberResult,
     * SeriesResult, ListResult or TableResult) rather than this interface: it is what says
     * which widgets can show the source. A source that has no use for a range, a count of
     * everything so far, leaves it unread.
     */
    public function resolve(DataRange $range): DataSourceResult;
}
