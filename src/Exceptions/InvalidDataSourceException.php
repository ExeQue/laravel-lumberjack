<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Exceptions;

use ExeQue\Lumberjack\Contracts\DataSource;
use InvalidArgumentException;

class InvalidDataSourceException extends InvalidArgumentException implements LumberjackExceptionInterface
{
    public static function notADataSource(string $given): self
    {
        return new self(sprintf('[%s] does not implement %s.', $given, DataSource::class));
    }

    public static function noResultType(string $declared): self
    {
        return new self(sprintf('A data source must declare the result class it returns (NumberResult, SeriesResult, ListResult or TableResult), not %s.', $declared));
    }

    public static function badKey(string $key): self
    {
        return new self(sprintf('Data source key [%s] is invalid: use 1 to 100 of a-z, 0-9, ":", ".", "_" and "-", starting with a letter or digit.', $key));
    }

    public static function duplicateKey(string $key): self
    {
        return new self(sprintf('Data source key [%s] is registered twice.', $key));
    }
}
