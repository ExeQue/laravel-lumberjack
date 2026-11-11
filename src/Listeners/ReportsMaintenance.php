<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Listeners;

use Closure;
use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Support\Client;
use ExeQue\Lumberjack\Support\Safely;
use ExeQue\LumberjackAgent\Sender;
use ExeQue\LumberjackAgent\SendResult;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Events\MaintenanceModeDisabled;
use Illuminate\Foundation\Events\MaintenanceModeEnabled;
use Throwable;

/**
 * Tells Lumberjack when artisan down and artisan up put the app in and out of maintenance.
 * Sent straight to Lumberjack, as the agent would send it, not in a batch that waits: the
 * app is going down, and its agent may be stopped next. When Lumberjack cannot be reached
 * it goes to the agent after all, which keeps it until Lumberjack is back.
 */
class ReportsMaintenance
{
    /** Seconds artisan down waits at most for Lumberjack before the agent takes the event. */
    public const int MAX_TIMEOUT = 3;

    /**
     * @param  Closure(): ?Sender  $sender  built when sending, so it uses the HTTP client of that moment
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Closure $sender,
        private readonly ?string $environment,
        private readonly ?int $expectedMinutes = null,
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(MaintenanceModeEnabled::class, Safely::wrap(fn () => $this->send('start', $this->expectedMinutes)));
        $events->listen(MaintenanceModeDisabled::class, Safely::wrap(fn () => $this->send('end')));
    }

    private function send(string $state, ?int $expectedMinutes = null): void
    {
        $event = [
            'type' => 'maintenance',
            'state' => $state,
            'message' => null,
            ...($state === 'start' ? ['expected_minutes' => $expectedMinutes] : []),
            'trace_id' => $this->recorder->traceId(),
            'timestamp' => microtime(true),
        ];

        $body = gzencode((string) json_encode([
            'v' => 1,
            'sdk' => ['name' => Client::SDK, 'version' => Client::version()],
            'environment' => $this->environment,
            'release' => $this->recorder->release(),
            'host' => gethostname() ?: null,
            'events' => [$event],
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES));

        // Sent, or refused as such (an agent would be refused too). A faked recorder keeps it.
        try {
            $sender = $this->recorder->faked() ? null : ($this->sender)();
        } catch (Throwable) {
            // Not built (the HTTP client cannot be resolved, say): the agent still takes it.
            $sender = null;
        }

        if ($sender !== null && $body !== false && $sender->send($body) !== SendResult::Retry) {
            return;
        }

        $this->recorder->record($event);
        $this->recorder->flush();
    }
}
