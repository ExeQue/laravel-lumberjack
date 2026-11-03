<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Checks;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;

final class DebugModeHealthCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    /**
     * @param  list<string>  $environments  where debug mode must be off
     */
    public function __construct(
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly array $environments = ['production'],
        private readonly int $timeout = 10,
        private readonly ?string $slug = null,
    ) {
    }

    public function run(): CheckResult
    {
        if (config('app.debug') && app()->environment($this->environments)) {
            return CheckResult::failed('Debug mode is on in '.app()->environment().'.');
        }

        return CheckResult::ok();
    }

    protected function defaultSlug(): string
    {
        return 'debug-mode';
    }
}
