<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Contracts;

use ExeQue\Lumberjack\Health\CheckResult;

/**
 * One thing lumberjack:health checks. Lumberjack marks it degraded after
 * degradeAfter() failed runs in a row, and healthy again after recoverAfter() good ones.
 */
interface HealthCheck
{
    /** The check's identity in Lumberjack: ^[a-z0-9][a-z0-9:._-]{0,99}$. */
    public function slug(): string;

    public function run(): CheckResult;

    public function degradeAfter(): int;

    public function recoverAfter(): int;

    /** Seconds the check may take before it counts as failed. */
    public function timeout(): int;
}
