<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

/**
 * The line in the app's own code that led to the current call: the first
 * frame outside vendor/ and outside this package, relative to the app.
 */
final class CallerLocation
{
    public const int MAX_FRAMES = 60;

    public function __construct(
        private readonly string $basePath,
        private readonly string $packagePath,
    ) {
    }

    public function find(): ?string
    {
        $vendor = DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR;

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::MAX_FRAMES) as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || ! str_starts_with($file, $this->basePath) || str_contains($file, $vendor) || str_starts_with($file, $this->packagePath)) {
                continue;
            }

            return substr($file, strlen($this->basePath) + 1).':'.($frame['line'] ?? 0);
        }

        return null;
    }
}
