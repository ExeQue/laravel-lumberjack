<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Checks;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Jobs that failed within the last withinMinutes, through the app's failed job provider.
 */
final class FailedJobsHealthCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $downAfter = 5,
        private readonly int $withinMinutes = 60,
        private readonly int $warnAt = 1,
        private readonly ?int $failAt = null,
        private readonly int $timeout = 10,
        private readonly ?string $slug = null,
    ) {
    }

    public function run(): CheckResult
    {
        /** @var FailedJobProviderInterface $failer */
        $failer = app('queue.failer');

        if ($failer instanceof NullFailedJobProvider) {
            return CheckResult::ok('Failed jobs are not stored.');
        }

        $since = Carbon::now()->subMinutes($this->withinMinutes);
        $count = $this->count($failer, $since);

        return CheckResult::threshold($count, $this->warnAt, $this->failAt, "{$count} failed in the last {$this->withinMinutes} minutes", ['count' => $count]);
    }

    private function count(FailedJobProviderInterface $failer, Carbon $since): int
    {
        $config = (array) config('queue.failed', []);

        // The database providers are asked directly, so their payloads stay in the database.
        if (in_array($config['driver'] ?? null, ['database', 'database-uuids'], true)) {
            return DB::connection(is_string($config['database'] ?? null) ? $config['database'] : null)
                ->table(is_string($config['table'] ?? null) ? $config['table'] : 'failed_jobs')
                ->where('failed_at', '>=', $since)
                ->count();
        }

        // ponytail: other providers (file, DynamoDB) list every failed job; fine for the
        // usual handful, slow for a provider holding many thousands.
        return count(array_filter($failer->all(), function (object $job) use ($since): bool {
            $at = $job->failed_at ?? null;

            return $at !== null && Carbon::parse(is_numeric($at) ? '@'.$at : $at)->gte($since);
        }));
    }

    protected function defaultSlug(): string
    {
        return 'failed-jobs';
    }
}
