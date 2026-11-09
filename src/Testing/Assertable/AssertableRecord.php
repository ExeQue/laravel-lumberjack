<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing\Assertable;

/**
 * Something recorded, checked by a test. Checks are collected rather than thrown, as each
 * record is tried in turn and only one of them has to pass.
 */
interface AssertableRecord
{
    public function passed(): bool;

    public function mismatches(): int;

    /** What was checked, with what differed marked. */
    public function describe(): string;
}
