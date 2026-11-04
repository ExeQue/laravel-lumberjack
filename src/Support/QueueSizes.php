<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Throwable;

/**
 * How many jobs wait on the queues the app names in lumberjack.queues, for the
 * heartbeat. Queue sizes keep growing when every worker is dead, which job runs
 * alone cannot show. A queue that cannot be measured is left out; this never throws.
 */
class QueueSizes
{
    /**
     * @param  list<string>  $queues  "connection:queue", or a queue on the default connection
     */
    public function __construct(
        private readonly QueueFactory $queue,
        private readonly array $queues = [],
        private readonly ?string $defaultConnection = null,
        private readonly ?QueueWorkers $workers = null,
    ) {
    }

    /**
     * @return list<array{connection: string, queue: string, size: int, oldest_age_s: int|null, workers?: int}>
     */
    public function measure(): array
    {
        $samples = [];

        foreach ($this->targets() as [$connection, $queue]) {
            try {
                $driver = $this->queue->connection($connection);
                $size = (int) $driver->size($queue);
            } catch (Throwable) {
                continue;
            }

            $workers = $this->workers?->count($connection, $queue);
            $samples[] = ['connection' => $connection, 'queue' => $queue, 'size' => $size, 'oldest_age_s' => $size > 0 ? $this->oldestAge($driver, $queue) : null]
                // Only where a worker has ever said so: an app without workers here sends none.
                + ($workers !== null ? ['workers' => $workers] : []);
        }

        return $samples;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function targets(): array
    {
        $default = $this->defaultConnection ?? (string) config('queue.default', 'sync');
        $queues = $this->queues !== [] ? $this->queues : [$default.':'.$this->defaultQueue($default)];
        $targets = [];

        foreach ($queues as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            [$connection, $queue] = str_contains($entry, ':') ? explode(':', $entry, 2) : [$default, $entry];
            $connection = $connection !== '' ? $connection : $default;
            $queue = $queue !== '' ? $queue : $this->defaultQueue($connection);
            $targets["{$connection}:{$queue}"] = [$connection, $queue];
        }

        return array_values($targets);
    }

    private function defaultQueue(string $connection): string
    {
        $queue = config("queue.connections.{$connection}.queue");

        return is_string($queue) && $queue !== '' ? $queue : 'default';
    }

    private function oldestAge(object $driver, string $queue): ?int
    {
        if (! method_exists($driver, 'creationTimeOfOldestPendingJob')) {
            return null;
        }

        try {
            $created = $driver->creationTimeOfOldestPendingJob($queue);
        } catch (Throwable) {
            return null;
        }

        return is_numeric($created) ? max(0, time() - (int) $created) : null;
    }
}
