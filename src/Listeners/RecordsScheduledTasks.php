<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Listeners;

use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Support\RecordingRules;
use ExeQue\Lumberjack\Support\Safely;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as Task;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reports each run of a scheduled task: started, finished, failed or skipped.
 * Every run gets its own trace, so its queries and logs sit with it.
 */
class RecordsScheduledTasks
{
    /** @var array<int, array{uuid: string, start: float}> runs in progress, by task */
    private array $running = [];

    /** @var array<int, true> tasks whose run just ended, by task */
    private array $ended = [];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly RecordingRules $rules,
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        if (! $this->rules->schedule) {
            return;
        }

        $events->listen(ScheduledTaskStarting::class, Safely::wrap(fn (ScheduledTaskStarting $e) => $this->starting($e->task)));
        $events->listen(ScheduledTaskFinished::class, Safely::wrap(fn (ScheduledTaskFinished $e) => $this->finished($e->task)));
        $events->listen(ScheduledTaskFailed::class, Safely::wrap(fn (ScheduledTaskFailed $e) => $this->failed($e->task, $e->exception)));
        $events->listen(ScheduledTaskSkipped::class, Safely::wrap(fn (ScheduledTaskSkipped $e) => $this->skipped($e->task)));
        $events->listen(ScheduledBackgroundTaskFinished::class, Safely::wrap(fn (ScheduledBackgroundTaskFinished $e) => $this->backgroundFinished($e->task)));
    }

    public static function name(Task $task): string
    {
        $command = $task->command;

        if ($task->description !== null && $task->description !== '') {
            return $task->description;
        }

        if (! is_string($command) || $command === '') {
            return $task->getSummaryForDisplay();
        }

        // "'/usr/bin/php8.4' 'artisan' reports:send" reads as "artisan reports:send".
        return trim((string) preg_replace('#^\S*php[^\s\']*\'?\s+\'?artisan\'?\s+#', 'artisan ', $command));
    }

    private function starting(Task $task): void
    {
        // A background task finishes in another process (schedule:finish); it is reported there.
        if ($task->runInBackground || $this->rules->ignoresTask(self::name($task))) {
            return;
        }

        $this->recorder->flush();
        $this->recorder->startTrace();
        $this->recorder->startRun(RecordingRules::sampled($this->rules->scheduleSampleRate), $this->rules->scheduleSampleRate);

        unset($this->ended[spl_object_id($task)]);
        $this->running[spl_object_id($task)] = ['uuid' => (string) Str::uuid(), 'start' => microtime(true)];
        $this->record($task, 'started');
    }

    private function finished(Task $task): void
    {
        $this->end($task, 'finished', ['exit_code' => $task->exitCode]);
    }

    private function failed(Task $task, Throwable $exception): void
    {
        // After a non-zero exit Laravel throws its own "Scheduled command [...] failed"
        // exception for a run that already ended as failed; it says nothing more.
        if (isset($this->ended[spl_object_id($task)])) {
            unset($this->ended[spl_object_id($task)]);
            $this->recorder->markReported($exception);

            return;
        }

        if (! isset($this->running[spl_object_id($task)])) {
            return;
        }

        // The scheduler reports the exception right after; it belongs to this run.
        $this->recorder->markReported($exception);
        $this->end($task, 'failed', ['exit_code' => $task->exitCode, 'exception' => $this->recorder->present($exception)]);
    }

    private function skipped(Task $task): void
    {
        if ($this->rules->ignoresTask(self::name($task))) {
            return;
        }

        $this->recorder->record($this->event($task, 'skipped', (string) Str::uuid()));
        $this->recorder->flush();
    }

    private function backgroundFinished(Task $task): void
    {
        if ($this->rules->ignoresTask(self::name($task))) {
            return;
        }

        // ponytail: the start is in the parent process, so a background run has no duration.
        $this->recorder->record([...$this->event($task, 'finished', (string) Str::uuid()), 'exit_code' => $task->exitCode]);
        $this->recorder->flush();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function end(Task $task, string $status, array $extra): void
    {
        $run = $this->running[spl_object_id($task)] ?? null;

        if ($run === null) {
            return;
        }

        unset($this->running[spl_object_id($task)]);
        $this->ended[spl_object_id($task)] = true;

        $duration = round((microtime(true) - $run['start']) * 1000, 3);

        $this->recorder->endRun(
            keep: $status !== 'finished' || RecordingRules::isSlow($duration, $this->rules->scheduleAlwaysSlowerThanMs),
            slowQueryMs: $this->rules->alwaysSlowerThanMs,
        );

        $this->recorder->record([
            ...$this->event($task, $status, $run['uuid']),
            ...$this->recorder->weight(),
            'duration_ms' => $duration,
            ...array_filter($extra, fn ($value): bool => $value !== null),
        ]);
        $this->recorder->flush();
    }

    private function record(Task $task, string $status): void
    {
        $run = $this->running[spl_object_id($task)];
        $this->recorder->record([...$this->event($task, $status, $run['uuid']), ...$this->recorder->weight()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function event(Task $task, string $status, string $uuid): array
    {
        return array_filter([
            'type' => 'schedule',
            'status' => $status,
            'uuid' => $uuid,
            'name' => self::name($task),
            'expression' => $task->expression,
            'timezone' => is_string($task->timezone) ? $task->timezone : ($task->timezone?->getName()),
            'next_run_at' => $this->nextRun($task),
        ], fn ($value): bool => $value !== null);
    }

    private function nextRun(Task $task): ?string
    {
        try {
            return $task->nextRunDate()->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
