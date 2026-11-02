<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

/**
 * A short, one-way mark of a server token. An app puts it on what it hands the
 * agent, so an agent can tell data meant for another project from its own
 * without the token itself travelling to it.
 */
final class ProjectFingerprint
{
    public static function of(?string $token): ?string
    {
        return $token === null || $token === '' ? null : substr(hash('sha256', $token), 0, 12);
    }
}
