<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Recorder;
use RuntimeException;
use Throwable;

/**
 * Runs a check within its timeout and records its result for Lumberjack; shared by
 * lumberjack:health and by checks the app fires itself.
 */
final class HealthRunner
{
    public function __construct(
        private readonly Recorder $recorder,
    ) {
    }

    /**
     * Runs one check; an exception or running past its timeout fails it.
     */
    public function run(HealthCheck $check): CheckResult
    {
        $timeout = $check->timeout();
        // ponytail: without pcntl a check that hangs is only failed once it returns.
        $alarm = $timeout > 0 && function_exists('pcntl_alarm') && function_exists('pcntl_async_signals');
        $start = hrtime(true);
        $outer = 0;

        if ($alarm) {
            $async = pcntl_async_signals(true);
            $previous = pcntl_signal_get_handler(SIGALRM);
            pcntl_signal(SIGALRM, function () use ($timeout): never {
                throw new RuntimeException("Timed out after {$timeout} s.");
            });
            // A queue worker's job timeout is an alarm too: it is never pushed back by the
            // check, and is put back afterwards with what is left of it.
            $outer = pcntl_alarm($timeout);

            if ($outer > 0 && $outer < $timeout) {
                pcntl_alarm($outer);
            }
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

                if ($outer > 0) {
                    pcntl_alarm(max(1, $outer - (int) ceil((hrtime(true) - $start) / 1e9)));
                }
            }
        }

        $duration = round((hrtime(true) - $start) / 1e6, 3);

        if ($timeout > 0 && $duration > $timeout * 1000) {
            $result = CheckResult::failed("Timed out after {$timeout} s.", $result->metrics);
        }

        return $result->withDuration($duration);
    }

    public function record(HealthCheck $check, CheckResult $result): void
    {
        $this->recorder->record([
            'type' => 'health',
            'slug' => $check->slug(),
            'status' => $result->status->value,
            'message' => $result->message,
            'metrics' => (object) $result->metrics,
            'duration_ms' => $result->durationMs,
            'degrade_after' => $check->degradeAfter(),
            'recover_after' => $check->recoverAfter(),
            'down_after' => $check->downAfter(),
        ]);
    }

    public function flush(): void
    {
        $this->recorder->flush();
    }
}
