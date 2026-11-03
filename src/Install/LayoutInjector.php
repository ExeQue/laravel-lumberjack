<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Install;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds the app's Blade layouts and puts the @lumberjack directive in their head.
 */
final class LayoutInjector
{
    /**
     * Every Blade view under the directory with a closing head tag, as paths relative to it.
     *
     * @return list<string>
     */
    public static function layouts(string $views): array
    {
        if (! is_dir($views)) {
            return [];
        }

        $layouts = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $path = (string) $file;

            if (str_ends_with($path, '.blade.php') && stripos((string) file_get_contents($path), '</head>') !== false) {
                $layouts[] = ltrim(str_replace('\\', '/', substr($path, strlen($views))), '/');
            }
        }

        sort($layouts);

        return $layouts;
    }

    public static function has(string $file): bool
    {
        return preg_match('/@lumberjack\b/', (string) file_get_contents($file)) === 1;
    }

    /**
     * Puts the directive on its own line just before </head>; false when it is there already
     * or the file has no head.
     */
    public static function inject(string $file): bool
    {
        $contents = (string) file_get_contents($file);

        if (self::has($file) || stripos($contents, '</head>') === false) {
            return false;
        }

        $injected = preg_replace_callback(
            '/^([ \t]*)<\/head>/mi',
            fn (array $match): string => $match[1].'    @lumberjack'."\n".$match[0],
            $contents,
            1,
            $count,
        );

        // </head> shares its line with something else: put the directive right before it.
        if ($count === 0) {
            $injected = (string) preg_replace('/<\/head>/i', '@lumberjack</head>', $contents, 1);
        }

        file_put_contents($file, $injected);

        return true;
    }
}
