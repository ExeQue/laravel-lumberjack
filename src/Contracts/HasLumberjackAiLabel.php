<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Contracts;

/**
 * A Laravel AI agent that knows the label of the key its calls are made with. It counts
 * for the agent's own steps; null leaves it to the scopes around it.
 */
interface HasLumberjackAiLabel
{
    public function lumberjackAiLabel(): ?string;
}
