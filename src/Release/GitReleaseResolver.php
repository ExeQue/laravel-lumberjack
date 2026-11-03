<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Release;

use ExeQue\Lumberjack\Contracts\ReleaseResolver;

/**
 * The commit checked out in the app's .git directory, read from its files without
 * running git: a branch's ref (loose or packed), a detached HEAD, or a worktree's
 * .git file pointing elsewhere. Null when there is no repository.
 */
final class GitReleaseResolver implements ReleaseResolver
{
    public function __construct(private readonly string $path)
    {
    }

    public function resolve(): ?string
    {
        $git = $this->gitDirectory();

        if ($git === null) {
            return null;
        }

        $head = $this->read($git.'/HEAD');

        if ($head === null) {
            return null;
        }

        if (! str_starts_with($head, 'ref: ')) {
            return $this->sha($head);
        }

        $ref = substr($head, 5);
        // A worktree keeps its HEAD but shares the refs of the main repository.
        $common = $this->read($git.'/commondir');
        $refs = $common === null ? $git : $this->absolute($common, $git);

        return $this->sha($this->read($git.'/'.$ref) ?? $this->read($refs.'/'.$ref) ?? $this->packed($refs, $ref));
    }

    private function gitDirectory(): ?string
    {
        $dot = rtrim($this->path, '/').'/.git';

        if (is_dir($dot)) {
            return $dot;
        }

        $file = $this->read($dot);

        return $file !== null && str_starts_with($file, 'gitdir: ') ? $this->absolute(substr($file, 8), dirname($dot)) : null;
    }

    private function packed(string $git, string $ref): ?string
    {
        foreach (explode("\n", $this->read($git.'/packed-refs') ?? '') as $line) {
            if (str_ends_with($line, ' '.$ref)) {
                return strtok($line, ' ') ?: null;
            }
        }

        return null;
    }

    private function sha(?string $value): ?string
    {
        return $value !== null && preg_match('/^[0-9a-f]{40,64}$/', $value) === 1 ? $value : null;
    }

    private function absolute(string $path, string $from): string
    {
        return str_starts_with($path, '/') ? $path : $from.'/'.$path;
    }

    private function read(string $file): ?string
    {
        $content = is_file($file) ? @file_get_contents($file) : false;

        return $content === false ? null : trim($content);
    }
}
