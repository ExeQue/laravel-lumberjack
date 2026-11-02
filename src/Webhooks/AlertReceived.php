<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Webhooks;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired for every alert the webhook receives, for listeners that want them all.
 */
final class AlertReceived
{
    use Dispatchable;

    public function __construct(
        public readonly Alert $alert,
    ) {
    }
}
