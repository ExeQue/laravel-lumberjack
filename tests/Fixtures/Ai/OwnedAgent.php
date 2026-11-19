<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ai;

use ExeQue\Lumberjack\Activity\Actor;
use ExeQue\Lumberjack\Contracts\ActsAsLumberjackActor;
use ExeQue\Lumberjack\Contracts\HasLumberjackAiActor;
use ExeQue\Lumberjack\Contracts\HasLumberjackAiLabel;
use ExeQue\Lumberjack\Contracts\HasLumberjackAiOwner;
use Laravel\Ai\AnonymousAgent;
use RuntimeException;

/**
 * An agent that knows who its calls are made for, or fails to say its owner when it throws.
 */
class OwnedAgent extends AnonymousAgent implements HasLumberjackAiActor, HasLumberjackAiLabel, HasLumberjackAiOwner
{
    /**
     * @param  iterable<mixed>  $tools
     */
    public function __construct(
        public ActsAsLumberjackActor|Actor|null $owner = null,
        public ?Actor $actor = null,
        public ?string $label = null,
        iterable $tools = [],
        public bool $throws = false,
    ) {
        parent::__construct('You help.', [], $tools);
    }

    public function lumberjackAiOwner(): ActsAsLumberjackActor|Actor|null
    {
        return $this->throws ? throw new RuntimeException('tenant lookup failed') : $this->owner;
    }

    public function lumberjackAiActor(): ?Actor
    {
        return $this->actor;
    }

    public function lumberjackAiLabel(): ?string
    {
        return $this->label;
    }
}
