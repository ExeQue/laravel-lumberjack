<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Console;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\CheckStatus;
use ExeQue\Lumberjack\Health\HealthRegistry;
use ExeQue\Lumberjack\Recorder;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * Runs the registered health checks and sends one health event per check.
 */
class HealthCommand extends Command
{
    protected $name = 'lumberjack:health';

    protected $description = 'Run the health checks and send their results to Lumberjack';

    public function handle(HealthRegistry $registry, Recorder $recorder): int
    {
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

        foreach ($checks as $slug => $check) {
            $result = $this->runCheck($check);
            $failed = $failed || $result->status === CheckStatus::Failed;
            $rows[] = [$slug, $result->status->value, $result->message ?? '', sprintf('%.1f ms', $result->durationMs)];

            $recorder->record([
                'type' => 'health',
                'slug' => $slug,
                'status' => $result->status->value,
                'message' => $result->message,
                'metrics' => (object) $result->metrics,
                'duration_ms' => $result->durationMs,
                'degrade_after' => $check->degradeAfter(),
                'recover_after' => $check->recoverAfter(),
            ]);
        }

        $recorder->flush();

        $this->table(['Check', 'Status', 'Message', 'Duration'], $rows);

        return $failed && ! $this->option('no-fail') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Runs one check; an exception or running past its timeout fails it.
     */
    private function runCheck(HealthCheck $check): CheckResult
    {
        $timeout = $check->timeout();
        // ponytail: without pcntl a check that hangs is only failed once it returns.
        $alarm = $timeout > 0 && function_exists('pcntl_alarm') && function_exists('pcntl_async_signals');
        $start = hrtime(true);

        if ($alarm) {
            $async = pcntl_async_signals(true);
            $previous = pcntl_signal_get_handler(SIGALRM);
            pcntl_signal(SIGALRM, function () use ($timeout): never {
                throw new RuntimeException("Timed out after {$timeout} s.");
            });
            pcntl_alarm($timeout);
        }

        try {
            $result = $check->run();
        } catch (Throwable $e) {
            $result = CheckResult::failed($e->getMessage() !== '' ? $e->getMessage() : $e::class);
        } finally {
            if ($alarm) {
                pcntl_alarm(0);
                pcntl_signal(SIGALRM, $previous);
                pcntl_async_signals($async);
            }
        }

        $duration = round((hrtime(true) - $start) / 1e6, 3);

        if ($timeout > 0 && $duration > $timeout * 1000) {
            $result = CheckResult::failed("Timed out after {$timeout} s.", $result->metrics);
        }

        return $result->withDuration($duration);
    }

    /**
     * @return list<InputOption>
     */
    protected function getOptions(): array
    {
        return [
            new InputOption('check', null, InputOption::VALUE_REQUIRED, 'Run only the check with this slug'),
            new InputOption('no-fail', null, InputOption::VALUE_NONE, 'Exit with 0 even when a check failed'),
        ];
    }
}
