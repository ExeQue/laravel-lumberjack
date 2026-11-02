<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Webhooks;

/**
 * What happened to an issue, as a Lumberjack webhook tells it.
 */
enum Status: string
{
    case New = 'new';
    case Reopened = 'reopened';
    case Escalating = 'escalating';
    case Resolved = 'resolved';
    case Ignored = 'ignored';
    case Note = 'note';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        return match ($payload['event'] ?? null) {
            'new_issue' => self::New,
            'regression' => self::Reopened,
            'escalation' => self::Escalating,
            'note' => self::Note,
            'status_change' => match ($payload['status_change']['status'] ?? null) {
                'resolved' => self::Resolved,
                'ignored' => self::Ignored,
                default => null,
            },
            default => null,
        };
    }
}
