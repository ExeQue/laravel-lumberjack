<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health;

use ExeQue\Lumberjack\Events\HealthStateChanged;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Where Lumberjack says this app's checks stand, kept in the app's cache. The agent
 * writes what its heartbeat gets back; when that is older than the ttl, the app asks
 * GET /api/health itself, at most once per ttl for everything sharing the cache. A
 * change of state dispatches HealthStateChanged in whichever process wrote it.
 * Reading never throws: without an answer a check is Unknown. Within a process, what
 * was read is kept for a few seconds (memo), so asking often costs one cache read.
 */
class HealthStates
{
    public const string KEY = 'lumberjack:health:states';

    public const int PULL_TIMEOUT = 2;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Http $http,
        private readonly Dispatcher $events,
        private readonly string $url,
        private readonly ?string $token,
        private readonly ?string $environment,
        private readonly ?string $store = null,
        private readonly int $ttl = 30,
        private readonly bool $pull = true,
        private readonly int $memo = 5,
    ) {
    }

    /** @var array<string, HealthStateSnapshot>|null what all() last read, kept for $memo seconds */
    private ?array $remembered = null;

    private float $rememberedAt = 0.0;

    public function get(string $slug): HealthState
    {
        return $this->snapshot($slug)->state ?? HealthState::Unknown;
    }

    public function snapshot(string $slug): ?HealthStateSnapshot
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * @return array<string, HealthStateSnapshot>
     */
    public function all(): array
    {
        // A page asking about many checks, or one check many times, reads the cache once.
        // ponytail: per process; a long-running worker sees a change up to $memo seconds late.
        if ($this->remembered !== null && Carbon::now()->getTimestampMs() / 1000 - $this->rememberedAt < $this->memo) {
            return $this->remembered;
        }

        try {
            $stored = $this->read();

            if ($this->pull && (int) ($stored['fetched_at'] ?? 0) < Carbon::now()->getTimestamp() - $this->ttl && $this->claimPull()) {
                $this->pullNow();
                $stored = $this->read();
            }

            return $this->remember($this->snapshots($stored['checks'] ?? []));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Stores the server's answer (the heartbeat's "health", or GET /api/health) and
     * dispatches HealthStateChanged for each check whose state moved.
     *
     * @param  array<mixed>  $health
     */
    public function fill(array $health): void
    {
        $checks = [];

        foreach (is_array($health['checks'] ?? null) ? $health['checks'] : [] as $check) {
            $environment = $check['environment'] ?? null;

            // A server that was not told the environment lists them all.
            if (is_array($check) && is_string($check['slug'] ?? null) && ($environment === null || $this->environment === null || $environment === $this->environment)) {
                $checks[$check['slug']] = HealthStateSnapshot::fromArray($check['slug'], $check)->toArray();
            }
        }

        $before = $this->snapshots($this->read()['checks'] ?? []);
        $this->repository()->forever(self::KEY, ['fetched_at' => Carbon::now()->getTimestamp(), 'checks' => $checks]);
        $after = $this->snapshots($checks);
        $this->remember($after);

        foreach ($after + $before as $slug => $snapshot) {
            $from = $before[$slug]->state ?? HealthState::Unknown;
            $now = $after[$slug] ?? new HealthStateSnapshot((string) $slug, HealthState::Unknown);

            if ($from !== $now->state) {
                $this->events->dispatch(new HealthStateChanged((string) $slug, $from, $now->state, $now));
            }
        }
    }

    /**
     * Asks Lumberjack now; false when it did not answer.
     */
    public function pullNow(): bool
    {
        if ($this->url === '' || $this->token === null || $this->token === '') {
            return false;
        }

        try {
            $response = $this->http->withToken($this->token)->timeout(self::PULL_TIMEOUT)->acceptJson()
                ->get($this->url.'/api/health', array_filter(['environment' => $this->environment]));
            $health = $response->successful() ? $response->json() : null;

            if (! is_array($health)) {
                return false;
            }

            $this->fill($health);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * One pull per ttl across every process sharing the cache, answered or not, so a
     * server that is down is not asked on every request.
     */
    private function claimPull(): bool
    {
        return $this->repository()->add(self::KEY.':pulled', true, max(1, $this->ttl));
    }

    /**
     * @param  array<string, HealthStateSnapshot>  $snapshots
     * @return array<string, HealthStateSnapshot>
     */
    private function remember(array $snapshots): array
    {
        if ($this->memo > 0) {
            $this->remembered = $snapshots;
            $this->rememberedAt = Carbon::now()->getTimestampMs() / 1000;
        }

        return $snapshots;
    }

    /**
     * @return array{fetched_at?: int, checks?: array<mixed>}
     */
    private function read(): array
    {
        $stored = $this->repository()->get(self::KEY);

        return is_array($stored) ? $stored : [];
    }

    /**
     * @param  array<mixed>  $checks
     * @return array<string, HealthStateSnapshot>
     */
    private function snapshots(array $checks): array
    {
        $snapshots = [];

        foreach ($checks as $slug => $check) {
            if (is_array($check)) {
                $snapshots[(string) $slug] = HealthStateSnapshot::fromArray((string) $slug, $check);
            }
        }

        return $snapshots;
    }

    private function repository(): Repository
    {
        return $this->cache->store($this->store);
    }
}
