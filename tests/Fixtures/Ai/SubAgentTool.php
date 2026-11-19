<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ai;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * A tool that asks another agent, the way an agent used as a tool does.
 */
class SubAgentTool implements Tool
{
    /**
     * @param  Closure(): mixed  $ask
     */
    public function __construct(private readonly Closure $ask)
    {
    }

    public function description(): Stringable|string
    {
        return 'Asks a sub-agent.';
    }

    public function handle(Request $request): Stringable|string
    {
        ($this->ask)();

        return 'asked';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
