<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ai;

use ExeQue\Lumberjack\Activity\Actor;
use ExeQue\Lumberjack\Contracts\HasLumberjackAiOwner;
use Laravel\Ai\AnonymousAgent;

/**
 * An agent that knows only who pays for its calls.
 */
class OwnerOnlyAgent extends AnonymousAgent implements HasLumberjackAiOwner
{
    public function __construct(public Actor $owner)
    {
        parent::__construct('You help.', [], []);
    }

    public function lumberjackAiOwner(): Actor
    {
        return $this->owner;
    }
}
