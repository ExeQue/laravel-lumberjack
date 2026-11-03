<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Exceptions;

use InvalidArgumentException;

class InvalidHealthCheckSlugException extends InvalidArgumentException implements LumberjackExceptionInterface
{
    public static function for(string $slug): self
    {
        return new self(sprintf('Health check slug [%s] is invalid: use 1 to 100 of a-z, 0-9, ":", ".", "_" and "-", starting with a letter or digit.', $slug));
    }
}
