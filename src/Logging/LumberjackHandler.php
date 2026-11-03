<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Logging;

use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Support\Safely;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Sends log lines to a Lumberjack log channel.
 */
class LumberjackHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly ?string $channel = null,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        Safely::run($this->record(...), $record);
    }

    private function record(LogRecord $record): void
    {
        $context = $record->context;
        $exception = $context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            // Laravel logs every reported exception; it is already an issue.
            if ($this->recorder->wasReported($exception)) {
                return;
            }

            $context['exception'] = $this->recorder->present($exception);
        }

        $this->recorder->record(array_filter([
            'type' => 'log',
            'channel' => $this->channel,
            'level' => strtolower($record->level->getName()),
            'message' => $record->message,
            'context' => [...$context, ...$record->extra] ?: null,
            'timestamp' => (float) $record->datetime->format('U.u'),
        ], fn ($value): bool => $value !== null));
    }
}
