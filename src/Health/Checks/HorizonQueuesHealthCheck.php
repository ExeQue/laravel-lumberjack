<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Checks;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Horizon\ProvisioningPlan;

/**
 * How much waits across every queue Horizon is configured to work. Read from its
 * configuration, not a running master, so the number is still right when Horizon is down.
 */
final class HorizonQueuesHealthCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $downAfter = 5,
        private readonly ?int $warnAt = null,
        private readonly ?int $failAt = null,
        private readonly int $timeout = 10,
        private readonly ?string $slug = null,
    ) {
    }

    public function run(): CheckResult
    {
        if (! class_exists(ProvisioningPlan::class)) {
            return CheckResult::failed('Horizon is not installed.');
        }

        $queues = $this->queues();
        $size = 0;

        foreach ($queues as [$connection, $queue]) {
            $size += (int) Queue::connection($connection)->size($queue);
        }

        return CheckResult::threshold($size, $this->warnAt, $this->failAt, "{$size} waiting on ".count($queues).' queues', ['size' => $size, 'queues' => count($queues)]);
    }

    protected function defaultSlug(): string
    {
        return 'horizon-queues';
    }

    /**
     * Each queue once, though more than one pool may work it.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function queues(): array
    {
        $plan = new ProvisioningPlan('lumberjack-health', (array) config('horizon.environments', []), (array) config('horizon.defaults', []));
        $environment = app()->environment();
        $queues = [];

        // The first match wins, wildcards included, the same as in Horizon.
        foreach ($plan->parsed as $name => $supervisors) {
            if (! Str::is($name, $environment)) {
                continue;
            }

            foreach ($supervisors as $options) {
                foreach (explode(',', (string) $options->queue) as $queue) {
                    $queues["{$options->connection}:{$queue}"] = [(string) $options->connection, $queue];
                }
            }

            break;
        }

        return array_values($queues);
    }
}
