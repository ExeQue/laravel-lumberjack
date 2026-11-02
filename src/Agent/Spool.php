<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Agent;

/**
 * Gzipped batches waiting on disk for Lumberjack to come back. The oldest
 * batch is dropped first when the spool is full.
 */
class Spool
{
    public function __construct(
        private readonly string $path,
        private readonly int $maxBytes,
    ) {
    }

    public function push(string $body): void
    {
        if (! is_dir($this->path)) {
            @mkdir($this->path, 0775, true);
        }

        $name = sprintf('%s/%020d-%s.json.gz', $this->path, hrtime(true), bin2hex(random_bytes(4)));
        @file_put_contents($name, $body, LOCK_EX);

        $this->trim();
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
