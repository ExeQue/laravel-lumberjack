<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Console;

use ExeQue\Lumberjack\Health\CheckStatus;
use ExeQue\Lumberjack\Health\HealthRegistry;
use ExeQue\Lumberjack\Health\HealthRunner;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * Runs the registered health checks and sends one health event per check. A check with
 * its own interval is skipped until it is due, unless --all or --check asks for it.
 */
class HealthCommand extends Command
{
    protected $name = 'lumberjack:health';

    protected $description = 'Run the health checks and send their results to Lumberjack';

    /** Seconds a due check may come early, so a tick's few seconds of jitter never push it to the next tick. */
    public const int DUE_SLACK_SECONDS = 30;

    public function handle(HealthRegistry $registry, HealthRunner $runner, CacheFactory $cache): int
    {
        if (! (bool) config('lumberjack.enabled', true)) {
            $this->components->info('Lumberjack is disabled (LUMBERJACK_ENABLED=false); no checks run.');

            return self::SUCCESS;
        }

        $checks = $registry->checks();
        $only = $this->option('check');

        if (is_string($only)) {
            if (! isset($checks[$only])) {
                $this->components->error("No health check [{$only}].");

                return self::FAILURE;
            }

            $checks = [$only => $checks[$only]];
        }

        if ($checks === []) {
            $this->components->warn('No health checks are registered.');

            return self::SUCCESS;
        }

        $rows = [];
        $failed = false;
        $force = is_string($only) || (bool) $this->option('all');

        foreach ($checks as $slug => $check) {
            $every = $registry->everyMinutes($check);

            if ($every !== null && ! $this->due($cache, $slug, $every, $force)) {
                $rows[] = [$slug, 'skipped', "Not due, runs every {$every} min", ''];

                continue;
            }

            $result = $runner->run($check);
            $failed = $failed || $result->status === CheckStatus::Failed;
            $rows[] = [$slug, $result->status->value, $result->message ?? '', sprintf('%.1f ms', $result->durationMs)];
            $runner->record($check, $result);
        }

        $runner->flush();

        $this->table(['Check', 'Status', 'Message', 'Duration'], $rows);

        return $failed && ! $this->option('no-fail') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Whether the check's last run, by any process of the app, is at least $minutes old;
     * marks it run when it is. In the cache store the scheduler's overlap lock uses; per
     * server when every server runs the checks (one_server off), as each reports its own.
     * A cache that is down does not stop the check.
     */
    private function due(CacheFactory $cache, string $slug, int $minutes, bool $force): bool
    {
        $key = 'lumberjack:health:ran:'.$slug.((bool) config('lumberjack.health.one_server', true) ? '' : ':'.gethostname());
        $seconds = max(1, $minutes * 60 - self::DUE_SLACK_SECONDS);

        try {
            $store = $cache->store();

            if ($force) {
                $store->put($key, true, $seconds);

                return true;
            }

            return $store->add($key, true, $seconds);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * @return list<InputOption>
     */
    protected function getOptions(): array
    {
        return [
            new InputOption('check', null, InputOption::VALUE_REQUIRED, 'Run only the check with this slug'),
            new InputOption('all', null, InputOption::VALUE_NONE, 'Run every check, also those with their own interval that are not due yet'),
            new InputOption('no-fail', null, InputOption::VALUE_NONE, 'Exit with 0 even when a check failed'),
        ];
    }
}
