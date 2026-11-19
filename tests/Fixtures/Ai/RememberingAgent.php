<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ai;

use Laravel\Ai\Concerns\RemembersConversations;

/**
 * An agent that knows its owner and remembers its conversations, so a title is generated.
 */
class RememberingAgent extends OwnedAgent
{
    use RemembersConversations;
}
