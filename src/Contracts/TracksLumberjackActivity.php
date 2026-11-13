<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Contracts;

use ExeQue\Lumberjack\PendingActivity;

/**
 * An event of the app that Lumberjack records as an activity when it is dispatched.
 */
interface TracksLumberjackActivity
{
    /**
     * The activity to record, built with Lumberjack::activity(), or null to record nothing
     * this time. The package sends it; do not call send().
     */
    public function toLumberjackActivity(): ?PendingActivity;
}
