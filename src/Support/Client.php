<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

use Composer\InstalledVersions;
use ExeQue\LumberjackAgent\AgentInfo;
use Illuminate\Foundation\Application;

/**
 * What this package is, as it tells Lumberjack: the SDK, the agent that ships its
 * data (exeque/lumberjack-agent), and the runtime and framework underneath. The ids stay put when packages are
 * renamed; other SDKs follow the same lumberjack.<language>.<integration> scheme.
 */
final class Client
{
    /**
     * Redirects Lumberjack's address may answer with: followed with the POST and its body
     * kept (http to https behind a proxy), only to https. Guzzle's default turns a POST
     * into an empty GET on a 301 or 302.
     */
    public const array REDIRECTS = ['max' => 5, 'strict' => true, 'protocols' => ['https']];

    public const string SDK = 'lumberjack.php.laravel';

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
            ...AgentInfo::describe(),
            'framework' => ['name' => 'laravel', 'version' => Application::VERSION],
        ];
    }
}
