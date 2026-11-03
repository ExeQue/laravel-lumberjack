<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health;

use Closure;
use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;
use Illuminate\Container\Container;

/**
 * A check written as a closure: true is ok, false failed, or a CheckResult.
 * The closure's parameters are resolved by the container.
 */
final class ClosureHealthCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly string $slug,
        private readonly Closure $callback,
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $timeout = 10,
    ) {
    }

    public function run(): CheckResult
    {
        $result = Container::getInstance()->call($this->callback);

        return match (true) {
            $result instanceof CheckResult => $result,
            $result === false => CheckResult::failed(),
            default => CheckResult::ok(),
        };
    }
}
