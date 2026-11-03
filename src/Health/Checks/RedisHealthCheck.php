<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Checks;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;
use Illuminate\Support\Facades\Redis;

final class RedisHealthCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly ?string $connection = null,
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $timeout = 10,
        private readonly ?string $slug = null,
    ) {
    }

    public function run(): CheckResult
    {
        $start = hrtime(true);
        // phpredis answers true (or "+PONG"), Predis a status object reading "PONG".
        $reply = Redis::connection($this->connection)->command('ping');
        $latency = self::since($start);

        if ($reply === false || $reply === null) {
            return CheckResult::failed('Redis did not answer PING.');
        }

        return CheckResult::ok(metrics: ['latency_ms' => $latency]);
    }

    protected function defaultSlug(): string
    {
        return 'redis:'.($this->connection ?? 'default');
    }
}
