<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

use Illuminate\Foundation\Application;

/**
 * What this package is, as it tells Lumberjack: the SDK, the agent that ships its
 * data, and the runtime and framework underneath. The ids stay put when packages are
 * renamed; other SDKs follow the same lumberjack.<language>.<integration> scheme.
 */
final class Client
{
    public const string SDK = 'lumberjack.php.laravel';

    public const string AGENT = 'lumberjack-agent-php';

    public const string VERSION = '0.1.0';

    /**
     * @return array<string, array{name: string, version: string}>
     */
    public static function describe(): array
    {
        return [
            'sdk' => ['name' => self::SDK, 'version' => self::VERSION],
            'agent' => ['name' => self::AGENT, 'version' => self::VERSION],
            'runtime' => ['name' => 'php', 'version' => PHP_VERSION],
            'framework' => ['name' => 'laravel', 'version' => Application::VERSION],
        ];
    }
}
