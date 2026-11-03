<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Checks;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class CacheHealthCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly ?string $store = null,
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
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

        return CheckResult::ok(metrics: ['latency_ms' => self::since($start)]);
    }

    protected function defaultSlug(): string
    {
        return 'cache:'.($this->store ?? config('cache.default'));
    }
}
