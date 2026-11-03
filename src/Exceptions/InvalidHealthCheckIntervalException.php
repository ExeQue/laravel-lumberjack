<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Exceptions;

use InvalidArgumentException;

class InvalidHealthCheckIntervalException extends InvalidArgumentException implements LumberjackExceptionInterface
{
    public static function tooShort(int $minutes, int $minimum): self
    {
        return new self(sprintf('Health check interval [%d] is invalid: use whole minutes, at least %d (how often lumberjack.health.schedule runs).', $minutes, $minimum));
    }

    public static function nothingAdded(): self
    {
        return new self('every() sets the interval of the check added just before it: call it right after add(), disk() or another check method.');
    }
}
