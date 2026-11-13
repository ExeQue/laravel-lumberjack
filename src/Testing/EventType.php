<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing;

/**
 * What the app records. An enum so a misspelled type cannot pass assertNotRecorded().
 */
enum EventType: string
{
    case Request = 'request';
    case RequestCounts = 'request_counts';
    case Query = 'query';
    case Span = 'span';
    case Exception = 'exception';
    case Job = 'job';
    case Log = 'log';
    case Schedule = 'schedule';
    case Command = 'command';
    case Health = 'health';
    case Alert = 'alert';
    case Maintenance = 'maintenance';
    case Activity = 'activity';
}
