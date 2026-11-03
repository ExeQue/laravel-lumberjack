<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Release;

use ExeQue\Lumberjack\Contracts\ReleaseResolver;

/**
 * The release written to a file by the deploy, e.g. `git rev-parse HEAD > REVISION`
 * before copying the app without its .git directory. The path is relative to the
 * app's root unless it starts with /. Null when the file is missing or empty.
 */
final class FileReleaseResolver implements ReleaseResolver
{
    public function __construct(
        private readonly string $path,
        private readonly string $file = 'REVISION',
    ) {
    }

    public function resolve(): ?string
    {
        $file = str_starts_with($this->file, '/') ? $this->file : rtrim($this->path, '/').'/'.$this->file;
        $content = is_file($file) ? trim((string) @file_get_contents($file)) : '';

        // The first line, capped at what Lumberjack stores.
        return $content === '' ? null : mb_substr(strtok($content, "\n") ?: $content, 0, 64);
    }
}
