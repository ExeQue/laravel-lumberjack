<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Checks;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;
use Illuminate\Support\Facades\Queue;

final class QueueHealthCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly ?string $connection = null,
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $downAfter = 5,
        private readonly ?string $queue = null,
        private readonly ?int $warnAt = null,
        private readonly ?int $failAt = null,
        private readonly int $timeout = 10,
        private readonly ?string $slug = null,
    ) {
    }

    public function run(): CheckResult
    {
        $size = Queue::connection($this->connection)->size($this->queue);

        return CheckResult::threshold($size, $this->warnAt, $this->failAt, "{$size} waiting", ['size' => $size]);
    }

    protected function defaultSlug(): string
    {
        $connection = $this->connection ?? config('queue.default');

        return "queue:{$connection}:".($this->queue ?? config("queue.connections.{$connection}.queue") ?? 'default');
    }
}
