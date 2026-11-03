<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Contracts;

/**
 * A health check that runs less often than lumberjack:health is scheduled. Optional, so
 * a check written against {@see HealthCheck} alone keeps working and runs every time.
 */
interface HasHealthCheckInterval
{
    /**
     * Minutes between runs; null runs it on every run. ->every() on the registry wins over it.
     */
    public function everyMinutes(): ?int;
}
