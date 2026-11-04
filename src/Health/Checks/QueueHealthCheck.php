<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Checks;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;
use ExeQue\Lumberjack\Support\QueueWorkers;
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
        $workers = app(QueueWorkers::class)->count(...$this->target());

        if ($workers === null) {
            return CheckResult::threshold($size, $this->warnAt, $this->failAt, "{$size} waiting", ['size' => $size]);
        }

        $message = "{$size} waiting · {$workers} ".($workers === 1 ? 'worker' : 'workers');
        $metrics = ['size' => $size, 'workers' => $workers];

        // Jobs wait and nobody takes them: down, whatever the size.
        return $workers === 0 && $size > 0
            ? CheckResult::failed($message, $metrics)
            : CheckResult::threshold($size, $this->warnAt, $this->failAt, $message, $metrics);
    }

    protected function defaultSlug(): string
    {
        return 'queue:'.implode(':', $this->target());
    }

    /** @return array{0: string, 1: string} the connection and the queue this check watches */
    private function target(): array
    {
        $connection = (string) ($this->connection ?? config('queue.default'));

        return [$connection, (string) ($this->queue ?? config("queue.connections.{$connection}.queue") ?? 'default')];
    }
}
