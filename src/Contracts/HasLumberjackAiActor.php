<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Contracts;

use ExeQue\Lumberjack\Activity\Actor;
use Illuminate\Database\Eloquent\Model;

/**
 * A Laravel AI agent that knows who its calls are made for. It counts for the agent's own
 * steps; null leaves it to the scopes around it.
 */
interface HasLumberjackAiActor
{
    public function lumberjackAiActor(): ActsAsLumberjackActor|Actor|Model|null;
}
