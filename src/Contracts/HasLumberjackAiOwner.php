<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Contracts;

use ExeQue\Lumberjack\Activity\Actor;
use Illuminate\Database\Eloquent\Model;

/**
 * A Laravel AI agent that knows who pays for its calls: the owner of the key, such as a
 * tenant bringing its own. It counts for the agent's own steps; null leaves it to the
 * scopes around it.
 */
interface HasLumberjackAiOwner
{
    public function lumberjackAiOwner(): ActsAsLumberjackActor|Actor|Model|null;
}
