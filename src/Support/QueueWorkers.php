<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * How many queue workers run per queue, from a sign of life each worker leaves in the
 * cache (`queue:work` and Horizon alike: both loop through the same worker). The cache
 * must be shared by every host running workers (redis, database); the file cache only
 * sees the workers of its own host. Never throws.
 */
class QueueWorkers
{
    private const string PREFIX = 'lumberjack:queue-workers:';

    /** A worker that has not looped for this long is gone. */
    private const int ALIVE_S = 90;

    /** How often one worker writes; a sign of life lasts three of these. */
    private const int BEAT_EVERY_S = 30;

    /** The list of a queue nobody has written to for this long is dropped. */
    private const int LIST_TTL_S = 86_400;

    /** @var array<string, array{0: int, 1: int}> when this process last wrote, and until when, per queue */
    private array $last = [];

    public function __construct(private readonly Repository $cache)
    {
    }

    /**
     * This worker is alive on these queues (comma separated, as a worker takes them) for
     * at least $aliveFor seconds: longer while it runs a long job, which never loops.
     */
    public function beat(string $connection, string $queues, int $aliveFor = self::ALIVE_S): void
    {
        $now = now()->getTimestamp();
        $until = $now + max(self::ALIVE_S, $aliveFor);

        foreach (array_filter(array_map(trim(...), explode(',', $queues))) as $queue) {
            $key = "{$connection}:{$queue}";

            // Throttled: once per BEAT_EVERY_S, unless this beat has to last longer.
            [$wroteAt, $wroteUntil] = $this->last[$key] ?? [0, 0];

            if ($now - $wroteAt < self::BEAT_EVERY_S && $until <= $wroteUntil) {
                continue;
            }

            $this->last[$key] = [$now, $until];

            try {
                // ponytail: read-modify-write without a lock; two workers beating at the
                // same moment can drop one until its next beat (30 s). A lock or a redis
                // hash per queue if that ever shows.
                $workers = $this->alive($this->read($key), $now);
                $workers[self::id()] = $until;
                $this->cache->put(self::PREFIX.$key, $workers, self::LIST_TTL_S);
            } catch (Throwable) {
                // A worker never fails because the cache is down.
            }
        }
    }

    /** Workers alive on the queue; null when none has ever said so (not measured). */
    public function count(string $connection, string $queue): ?int
    {
        try {
            $workers = $this->cache->get(self::PREFIX."{$connection}:{$queue}");
        } catch (Throwable) {
            return null;
        }

        return is_array($workers) ? count($this->alive($workers, now()->getTimestamp())) : null;
    }

    /** @return array<string, int> */
    private function read(string $key): array
    {
        $workers = $this->cache->get(self::PREFIX.$key);

        return is_array($workers) ? $workers : [];
    }

    /**
     * @param  array<mixed>  $workers
     * @return array<string, int>
     */
    private function alive(array $workers, int $now): array
    {
        $alive = [];

        foreach ($workers as $id => $until) {
            if (is_numeric($until) && (int) $until > $now) {
                $alive[(string) $id] = (int) $until;
            }
        }

        return $alive;
    }

    private static function id(): string
    {
        return (gethostname() ?: 'host').':'.getmypid();
    }
}
