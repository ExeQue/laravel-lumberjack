<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ai;

use ExeQue\Lumberjack\Activity\Actor;
use ExeQue\Lumberjack\Facades\Lumberjack;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * A tool whose AI call the platform pays for, whatever tenant the agent runs for.
 */
class PlatformTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Embeds on the platform\'s key.';
    }

    public function handle(Request $request): Stringable|string
    {
        Lumberjack::ai(fn () => Lumberjack::aiUsage(provider: 'voyageai', model: 'voyage-3', input: 5, operation: 'embeddings'), owner: new Actor('platform', 1), label: 'platform');

        return 'embedded';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
