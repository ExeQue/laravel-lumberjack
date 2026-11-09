<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Exceptions;

use UnexpectedValueException;

class InvalidDataSourceResultException extends UnexpectedValueException implements LumberjackExceptionInterface
{
}
