<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Checks;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;

/**
 * Whether a Horizon master supervisor runs and is not paused, as horizon:status sees it.
 */
final class HorizonHealthCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    // By name, as Horizon is not a dependency of this package.
    private const string REPOSITORY = 'Laravel\Horizon\Contracts\MasterSupervisorRepository';

    public function __construct(
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $timeout = 10,
        private readonly ?string $slug = null,
    ) {
    }

    public function run(): CheckResult
    {
        if (! interface_exists(self::REPOSITORY)) {
            return CheckResult::failed('Horizon is not installed (laravel/horizon).');
        }

        /** @var list<object{status: string}> $masters */
        $masters = (array) app(self::REPOSITORY)->all();

        if ($masters === []) {
            return CheckResult::failed('Horizon is not running.');
        }

        if (array_filter($masters, fn (object $master): bool => ($master->status ?? null) === 'paused') !== []) {
            return CheckResult::failed('Horizon is paused.');
        }

        return CheckResult::ok('Horizon is running.');
    }

    protected function defaultSlug(): string
    {
        return 'horizon';
    }
}
