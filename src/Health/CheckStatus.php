<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Failed = 'failed';
}
