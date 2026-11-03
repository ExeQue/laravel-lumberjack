<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Contracts;

use ExeQue\Lumberjack\Health\CheckResult;

/**
 * One thing lumberjack:health checks. Lumberjack marks it degraded after
 * degradeAfter() bad runs in a row (warnings or failures), down after downAfter()
 * failed runs in a row (never fewer than degradeAfter()), and healthy again after
 * recoverAfter() good ones.
 */
interface HealthCheck
{
    /** The check's identity in Lumberjack: ^[a-z0-9][a-z0-9:._-]{0,99}$. */
    public function slug(): string;

    public function run(): CheckResult;

    public function degradeAfter(): int;

    public function recoverAfter(): int;

    public function downAfter(): int;

    /** Seconds the check may take before it counts as failed. */
    public function timeout(): int;

    /**
     * Minutes between runs when the check should run less often than lumberjack:health
     * is scheduled; null runs it on every run. ->every() on the registry wins over it.
     */
    public function everyMinutes(): ?int;
}
