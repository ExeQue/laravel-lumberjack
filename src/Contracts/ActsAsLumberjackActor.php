<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Contracts;

use ExeQue\Lumberjack\Activity\Actor;

/**
 * An object that says who it is to Lumberjack when it is an activity's actor, in place of
 * its class (or morph alias) and key: by(), byUser(), forgetActor() and a model in a
 * payload all use it, so forgetting it finds what was recorded.
 */
interface ActsAsLumberjackActor
{
    public function toLumberjackActor(): Actor;
}
