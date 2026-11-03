<?php

declare(strict_types=1);

namespace Workbench\App\Listeners;

use ExeQue\Lumberjack\Events\HealthStateChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Logs each change Lumberjack reports to the lumberjack channel, so it shows on the
 * Logs page. Queued: the change mostly arrives in the agent, which records nothing.
 */
class LogHealthStateChange implements ShouldQueue
{
    public function handle(HealthStateChanged $event): void
    {
        Log::channel('lumberjack')->log($event->to->isBad() ? 'warning' : 'info', 'Health check {slug} went from {from} to {to}', [
            'slug' => $event->slug,
            'from' => $event->from->value,
            'to' => $event->to->value,
            'message' => $event->snapshot->message,
        ]);
    }
}
