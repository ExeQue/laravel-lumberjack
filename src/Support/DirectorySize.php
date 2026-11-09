<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * How many bytes a local folder holds, or null when that takes too long to find out.
 */
final class DirectorySize
{
    public static function of(string $path, int $withinSeconds): ?int
    {
        if (! is_dir($path)) {
            return 0;
        }

        return self::throughDu($path, $withinSeconds) ?? self::walk($path, microtime(true) + $withinSeconds);
    }

    /**
     * A big folder is far quicker through du. Null when it ran out of time as well as
     * when there is no du or timeout here; the walk then has its own deadline.
     */
    private static function throughDu(string $path, int $withinSeconds): ?int
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $output = @shell_exec("timeout {$withinSeconds} du -sb ".escapeshellarg($path).' 2>/dev/null');
        $bytes = is_string($output) ? strtok($output, "\t") : false;

        return is_string($bytes) && ctype_digit($bytes) ? (int) $bytes : null;
    }

    private static function walk(string $path, float $deadline): ?int
    {
        $bytes = 0;

        try {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (microtime(true) > $deadline) {
                    return null;
                }

                $bytes += $file->isFile() ? $file->getSize() : 0;
            }
        } catch (Throwable) {
            return null;
        }

        return $bytes;
    }
}
