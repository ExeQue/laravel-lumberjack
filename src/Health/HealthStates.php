<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health;

use Closure;
use ExeQue\Lumberjack\Events\HealthStateChanged;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Where Lumberjack says this app's checks stand, kept in the app's cache. The agent
 * writes what its heartbeat gets back. Only when the agent has not done so lately and
 * the states are older than the ttl does the app ask GET /api/health itself, at most
 * once per ttl for everything sharing the cache, and never inside a web request: the
 * stale states are read now and the pull runs after the response is sent (in the
 * console, right away with a short timeout). A change of state dispatches
 * HealthStateChanged in whichever process wrote it. Reading never throws: without an
 * answer a check is Unknown. Within a process, what was read (or that the cache could
 * not be read) is kept for a few seconds (memo), so asking often costs one cache read.
 */
class HealthStates
{
    public const string KEY = 'lumberjack:health:states';

    public const float PULL_TIMEOUT = 0.5;

    /** States the agent wrote this recently need no pull: its heartbeat comes every minute. */
    public const int AGENT_FRESH_SECONDS = 150;

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
        private readonly ?Closure $later = null,
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

            if ($this->pull && $this->stale($stored) && $this->claimPull()) {
                if ($this->later !== null) {
                    // After the response; this request makes do with what is stored.
                    ($this->later)(fn () => $this->pullNow());
                } else {
                    $this->pullNow();
                    $stored = $this->read();
                }
            }

            return $this->remember($this->snapshots($stored['checks'] ?? []));
        } catch (Throwable) {
            // The cache is down: not asked again for $memo seconds.
            return $this->remember([]);
        }
    }

    /**
     * Stores the server's answer (the heartbeat's "health", or GET /api/health) and
     * dispatches HealthStateChanged for each check whose state moved.
     *
     * @param  array<mixed>  $health
     * @param  bool  $fromAgent  written by the agent's heartbeat, so no pull is needed for a while
     */
    public function fill(array $health, bool $fromAgent = false): void
    {
        $checks = [];

        foreach (is_array($health['checks'] ?? null) ? $health['checks'] : [] as $check) {
            $environment = $check['environment'] ?? null;

            // A server that was not told the environment lists them all.
            if (is_array($check) && is_string($check['slug'] ?? null) && ($environment === null || $this->environment === null || $environment === $this->environment)) {
                $checks[$check['slug']] = HealthStateSnapshot::fromArray($check['slug'], $check)->toArray();
            }
        }

        $stored = $this->read();
        $before = $this->snapshots($stored['checks'] ?? []);
        $now = Carbon::now()->getTimestamp();
        $this->repository()->forever(self::KEY, array_filter([
            'fetched_at' => $now,
            'agent_at' => $fromAgent ? $now : ($stored['agent_at'] ?? null),
            'checks' => $checks,
        ], fn ($value): bool => $value !== null));
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
            $response = $this->http->withToken($this->token)->connectTimeout(self::PULL_TIMEOUT)->timeout(self::PULL_TIMEOUT)->acceptJson()
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
     * @param  array{fetched_at?: int, agent_at?: int, checks?: array<mixed>}  $stored
     */
    private function stale(array $stored): bool
    {
        $now = Carbon::now()->getTimestamp();

        return (int) ($stored['fetched_at'] ?? 0) < $now - $this->ttl
            && (int) ($stored['agent_at'] ?? 0) < $now - self::AGENT_FRESH_SECONDS;
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
     * @return array{fetched_at?: int, agent_at?: int, checks?: array<mixed>}
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
