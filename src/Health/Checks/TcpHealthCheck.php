<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Checks;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;

/**
 * Something answers on a port. A null host means the service does not run separately
 * here, so there is nothing to connect to.
 */
final class TcpHealthCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly ?string $host,
        private readonly int $port,
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $downAfter = 5,
        private readonly int $timeout = 10,
        private readonly ?string $slug = null,
    ) {
    }

    public function run(): CheckResult
    {
        if ($this->host === null) {
            return CheckResult::ok('No host to check.');
        }

        $start = microtime(true);
        $connection = @fsockopen($this->host, $this->port, timeout: $this->timeout);

        if ($connection === false) {
            return CheckResult::failed("Nothing answers on {$this->host}:{$this->port}.");
        }

        fclose($connection);

        return CheckResult::ok(metrics: ['latency_ms' => round((microtime(true) - $start) * 1000, 3)]);
    }

    protected function defaultSlug(): string
    {
        return 'tcp:'.($this->host ?? 'none').':'.$this->port;
    }
}
