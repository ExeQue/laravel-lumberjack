<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Checks;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;
use ExeQue\Lumberjack\Support\DirectorySize;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class CacheHealthCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly ?string $store = null,
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $downAfter = 5,
        private readonly int $timeout = 10,
        private readonly ?string $slug = null,
    ) {
    }

    public function run(): CheckResult
    {
        $cache = Cache::store($this->store);
        $key = 'lumberjack-health-'.Str::random(16);
        $value = Str::random(32);
        $start = hrtime(true);

        try {
            $cache->put($key, $value, 60);

            if ($cache->get($key) !== $value) {
                return CheckResult::failed('The cache did not return what was stored.');
            }
        } finally {
            $cache->forget($key);
        }

        return CheckResult::ok(metrics: ['latency_ms' => self::since($start), ...$this->directoryMetrics()]);
    }

    /**
     * What a file store holds. Never at the cost of the check: half its time, and without
     * a number if that runs out.
     *
     * @return array{directory_bytes?: int}
     */
    private function directoryMetrics(): array
    {
        $store = (array) config('cache.stores.'.($this->store ?? config('cache.default')));

        if (($store['driver'] ?? null) !== 'file' || ! is_string($store['path'] ?? null)) {
            return [];
        }

        $bytes = DirectorySize::of($store['path'], max(1, intdiv($this->timeout, 2)));

        return $bytes === null ? [] : ['directory_bytes' => $bytes];
    }

    protected function defaultSlug(): string
    {
        return 'cache:'.($this->store ?? config('cache.default'));
    }
}
