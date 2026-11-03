<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

use Composer\InstalledVersions;
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

    public const string PACKAGE = 'exeque/laravel-lumberjack';

    private static ?string $version = null;

    /**
     * The installed version as Composer has it: "0.2.0" for a tag, "dev-main" for a
     * branch, "dev" when Composer cannot tell.
     */
    public static function version(): string
    {
        return self::$version ??= ltrim((class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE)
            ? InstalledVersions::getPrettyVersion(self::PACKAGE)
            : null) ?? 'dev', 'v');
    }

    /**
     * @return array<string, array{name: string, version: string}>
     */
    public static function describe(): array
    {
        return [
            'sdk' => ['name' => self::SDK, 'version' => self::version()],
            'agent' => ['name' => self::AGENT, 'version' => self::version()],
            'runtime' => ['name' => 'php', 'version' => PHP_VERSION],
            'framework' => ['name' => 'laravel', 'version' => Application::VERSION],
        ];
    }
}
