<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Install;

/**
 * Reads and sets single keys in a .env file, leaving every other byte as it was.
 */
final class EnvFile
{
    public function __construct(public readonly string $path)
    {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * The key's value without its quotes; null when the key is not in the file.
     */
    public function get(string $key): ?string
    {
        if (! preg_match('/^'.preg_quote($key, '/').'=([^\r\n]*)/m', $this->contents(), $match)) {
            return null;
        }

        $value = trim($match[1]);

        return preg_match('/^(["\'])(.*)\1$/', $value, $quoted) ? stripcslashes($quoted[2]) : $value;
    }

    /**
     * Replaces the key's line, or appends one after the file's last LUMBERJACK_ line or at the end.
     */
    public function set(string $key, string $value): void
    {
        $line = $key.'='.self::quote($value);
        $contents = $this->contents();
        $pattern = '/^'.preg_quote($key, '/').'=[^\r\n]*/m';

        if (preg_match($pattern, $contents)) {
            file_put_contents($this->path, preg_replace_callback($pattern, fn (): string => $line, $contents, 1));

            return;
        }

        // Greedy from the start, so the match ends with the last LUMBERJACK_ line.
        if (preg_match('/\A.*^LUMBERJACK_[A-Z0-9_]*=[^\r\n]*/ms', $contents, $match)) {
            $at = strlen($match[0]);
            file_put_contents($this->path, substr($contents, 0, $at)."\n".$line.substr($contents, $at));

            return;
        }

        $separator = match (true) {
            $contents === '' => '',
            str_ends_with($contents, "\n\n") => '',
            str_ends_with($contents, "\n") => "\n",
            default => "\n\n",
        };

        file_put_contents($this->path, $contents.$separator.$line."\n");
    }

    private function contents(): string
    {
        return $this->exists() ? (string) file_get_contents($this->path) : '';
    }

    private static function quote(string $value): string
    {
        return $value === '' || preg_match('/^[A-Za-z0-9_.,:\/@+\-]+$/', $value)
            ? $value
            : '"'.addcslashes($value, '"\\').'"';
    }
}
