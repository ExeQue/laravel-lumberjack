<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Checks;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;
use Illuminate\Support\Facades\DB;

final class DatabaseHealthCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly ?string $connection = null,
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $downAfter = 5,
        private readonly ?int $maxLatencyMs = null,
        private readonly int $timeout = 10,
        private readonly ?string $slug = null,
    ) {
    }

    public function run(): CheckResult
    {
        $start = hrtime(true);
        DB::connection($this->connection)->select('select 1');
        $latency = self::since($start);

        return $this->maxLatencyMs !== null && $latency > $this->maxLatencyMs
            ? CheckResult::warning("Took {$latency} ms, more than {$this->maxLatencyMs} ms.", ['latency_ms' => $latency])
            : CheckResult::ok(metrics: ['latency_ms' => $latency]);
    }

    protected function defaultSlug(): string
    {
        return 'database:'.($this->connection ?? config('database.default'));
    }
}
