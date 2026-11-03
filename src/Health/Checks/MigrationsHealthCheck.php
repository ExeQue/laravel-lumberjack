<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Checks;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;
use Illuminate\Database\Migrations\Migrator;

/**
 * Pending migrations, counted the way migrate:status does: the migration files in the
 * migrator's paths and database/migrations against the ones that ran.
 */
final class MigrationsHealthCheck implements HealthCheck
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
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        return $migrator->usingConnection($this->connection, function () use ($migrator): CheckResult {
            if (! $migrator->repositoryExists()) {
                return CheckResult::failed('The migrations table does not exist.');
            }

            $files = $migrator->getMigrationFiles([...$migrator->paths(), database_path('migrations')]);
            $pending = count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));

            return $pending === 0
                ? CheckResult::ok(metrics: ['pending' => 0])
                : CheckResult::failed("{$pending} pending ".($pending === 1 ? 'migration' : 'migrations').'.', ['pending' => $pending]);
        });
    }

    protected function defaultSlug(): string
    {
        return 'migrations:'.($this->connection ?? config('database.default'));
    }
}
