<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Agent;

use Closure;

/**
 * Gzipped batches waiting on disk for Lumberjack to come back. The oldest
 * batch is dropped first when the spool is full.
 */
class Spool
{
    private bool $warned = false;

    public function __construct(
        private readonly string $path,
        private readonly int $maxBytes,
        private readonly ?Closure $warn = null,
    ) {
    }

    /**
     * Only the agent's user can read a batch: it holds the app's data.
     */
    public function push(string $body): void
    {
        if (! is_dir($this->path)) {
            @mkdir($this->path, 0700, true);
        }

        $name = sprintf('%s/%020d-%s.json.gz', $this->path, hrtime(true), bin2hex(random_bytes(4)));
        $old = umask(0077);
        $written = @file_put_contents($name, $body, LOCK_EX);
        umask($old);

        if ($written === false) {
            $this->failed("Could not write to the spool at {$this->path}; batches are dropped while Lumberjack cannot be reached. Make it writable by the agent's user.");

            return;
        }

        $this->trim();
    }

    private function failed(string $message): void
    {
        if ($this->warned) {
            return;
        }

        $this->warned = true;
        @error_log('Lumberjack agent: '.$message);
        ($this->warn)?->__invoke($message);
    }

    public function oldest(): ?string
    {
        return $this->files()[0] ?? null;
    }

    public function count(): int
    {
        return count($this->files());
    }

    public function read(string $file): ?string
    {
        $body = @file_get_contents($file);

        return $body === false ? null : $body;
    }

    public function delete(string $file): void
    {
        @unlink($file);
    }

    private function trim(): void
    {
        $files = $this->files();
        $total = array_sum(array_map(fn (string $file): int => (int) @filesize($file), $files));

        while ($total > $this->maxBytes && $files !== []) {
            $file = array_shift($files);
            $total -= (int) @filesize($file);
            $this->delete($file);
        }
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->path.'/*.json.gz') ?: [];
        sort($files);

        return $files;
    }
}
