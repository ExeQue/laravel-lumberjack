<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Console;

use ExeQue\Lumberjack\Health\CheckStatus;
use ExeQue\Lumberjack\Health\HealthRegistry;
use ExeQue\Lumberjack\Health\HealthRunner;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Runs the registered health checks and sends one health event per check.
 */
class HealthCommand extends Command
{
    protected $name = 'lumberjack:health';

    protected $description = 'Run the health checks and send their results to Lumberjack';

    public function handle(HealthRegistry $registry, HealthRunner $runner): int
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
