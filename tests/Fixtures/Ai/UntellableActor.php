<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ai;

use ExeQue\Lumberjack\Activity\Actor;
use ExeQue\Lumberjack\Contracts\ActsAsLumberjackActor;
use RuntimeException;

/**
 * An actor that cannot say who it is, as one missing from an enforced morph map.
 */
class UntellableActor implements ActsAsLumberjackActor
{
    public function toLumberjackActor(): Actor
    {
        throw new RuntimeException('not in the morph map');
    }
}
