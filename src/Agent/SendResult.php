<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Agent;

enum SendResult
{
    case Sent;
    case Retry;
    case Rejected;
}
