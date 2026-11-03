<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Release;

use Composer\InstalledVersions;
use ExeQue\Lumberjack\Contracts\ReleaseResolver;

/**
 * The commit Composer saw when vendor was installed: the root package's reference in
 * vendor/composer/installed.php. Survives a deploy that copies the app without .git,
 * as long as composer install ran in a git checkout (e.g. on CI). Null otherwise.
 */
final class ComposerReleaseResolver implements ReleaseResolver
{
    public function resolve(): ?string
    {
        if (! class_exists(InstalledVersions::class)) {
            return null;
        }

        $reference = InstalledVersions::getRootPackage()['reference'] ?? null;

        return is_string($reference) && preg_match('/^[0-9a-f]{40,64}$/', $reference) === 1 ? $reference : null;
    }
}
