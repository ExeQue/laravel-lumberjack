<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Listeners;

use Closure;
use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Support\JobParameters;
use ExeQue\Lumberjack\Support\RecordingRules;
use ExeQue\Lumberjack\Support\Safely;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Reports every artisan command run with the arguments and options it was
 * given. The outermost command gets its own trace; one it calls shares it.
 */
class RecordsCommands
{
    /** Symfony's global options; on every command, so they say nothing. */
    private const array GLOBAL_OPTIONS = ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env', 'silent'];

    /** @var list<array{uuid: string, name: string, start: float, exceptions: int}> */
    private array $running = [];

    /**
     * Failed runs held back until it is known whether an exception caused them:
     * Laravel reports that exception only after the command has finished.
     *
     * @var list<array{event: array<string, mixed>, exceptions: int}>
     */
    private array $failures = [];

    /** Whether the outermost running command is one the scheduler runs. */
    private bool $scheduled = false;

    /**
     * @param  (Closure(string): bool)|null  $isScheduled  whether the app's scheduler runs this command
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly RecordingRules $rules,
        private readonly ?Closure $isScheduled = null,
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        if (! $this->rules->commands) {
            return;
        }

        $events->listen(CommandStarting::class, Safely::wrap(fn (CommandStarting $e) => $this->starting($e->command)));
        $events->listen(CommandFinished::class, Safely::wrap(fn (CommandFinished $e) => $this->finished($e->command, $e->input, $e->exitCode)));
    }

    /**
     * A command that died on an exception never fires CommandFinished; it ends here.
     */
    public function finishOpen(): void
    {
        while ($this->running !== []) {
            $run = array_pop($this->running);
            $this->failures[] = ['event' => $this->event($run, 'finished', null) + ['exit_code' => 1], 'exceptions' => $run['exceptions']];
        }

        $this->sendFailures();
    }

    private function sendFailures(): void
    {
        foreach ($this->failures as $failure) {
            $this->recorder->record($failure['event'] + ['exception' => $this->recorder->exceptionCount() > $failure['exceptions']]);
        }

        $this->failures = [];
    }

    private function starting(?string $name): void
    {
        if ($name === null || $this->rules->ignoresCommand($name)) {
            return;
        }

        if ($this->running === []) {
            $this->sendFailures();
            $this->recorder->flush();
            $this->recorder->startTrace();
            // A scheduled command runs in a process of its own; it takes the scheduler's rate.
            $this->scheduled = $this->isScheduled !== null && ($this->isScheduled)($name);
            $this->recorder->startRun(RecordingRules::sampled($this->scheduled ? $this->rules->scheduleSampleRate : $this->rules->commandsSampleRate));
        }

        $run = ['uuid' => (string) Str::uuid(), 'name' => $name, 'start' => microtime(true), 'exceptions' => $this->recorder->exceptionCount()];
        $this->running[] = $run;
        $this->recorder->record($this->event($run, 'started', null));
    }

    private function finished(?string $name, ?InputInterface $input, int $exitCode): void
    {
        $last = $this->running[array_key_last($this->running) ?? 0] ?? null;

        if ($name === null || $last === null || $last['name'] !== $name) {
            return;
        }

        array_pop($this->running);

        $event = $this->event($last, 'finished', $input) + ['exit_code' => $exitCode];

        // The outermost run ends: what it held goes out before its last event.
        if ($this->running === []) {
            $duration = (microtime(true) - $last['start']) * 1000;
            $slow = $this->scheduled ? $this->rules->scheduleAlwaysSlowerThanMs : $this->rules->commandsAlwaysSlowerThanMs;
            $this->recorder->endRun(keep: $exitCode !== 0 || RecordingRules::isSlow($duration, $slow), slowQueryMs: $this->rules->alwaysSlowerThanMs);
        }

        if ($exitCode !== 0) {
            // ponytail: held until the app terminates or the next command starts; a command
            // called from a queue job that fails waits for one of those.
            $this->failures[] = ['event' => $event, 'exceptions' => $last['exceptions']];
        } else {
            $this->recorder->record($event + ['exception' => false]);
        }

        if ($this->running === []) {
            $this->recorder->flush();
        }
    }

    /**
     * @param  array{uuid: string, name: string, start: float, exceptions: int}  $run
     * @return array<string, mixed>
     */
    private function event(array $run, string $status, ?InputInterface $input): array
    {
        $event = ['type' => 'command', 'status' => $status, 'uuid' => $run['uuid'], 'name' => $run['name']];

        if ($status === 'finished') {
            $event['duration_ms'] = round((microtime(true) - $run['start']) * 1000, 3);
        }

        // Only bound to the command's definition once it ran, so only known when it finished.
        if ($input !== null) {
            $arguments = array_filter($input->getArguments(), fn ($value, $key): bool => is_string($key) && $key !== 'command', ARRAY_FILTER_USE_BOTH);
            $options = array_filter(
                array_diff_key($input->getOptions(), array_flip(self::GLOBAL_OPTIONS)),
                fn ($value): bool => $value !== null && $value !== false && $value !== [],
            );

            $event['arguments'] = array_map(JobParameters::value(...), array_filter($arguments, fn ($value): bool => $value !== null && $value !== []));
            $event['options'] = array_map(JobParameters::value(...), $options);
        }

        return $event;
    }
}
