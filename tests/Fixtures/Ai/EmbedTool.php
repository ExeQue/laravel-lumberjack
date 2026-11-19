<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ai;

use ExeQue\Lumberjack\Facades\Lumberjack;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * A tool that makes an AI call of its own, as one that embeds a search query would.
 */
class EmbedTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Embeds the query.';
    }

    public function handle(Request $request): Stringable|string
    {
        Lumberjack::aiUsage(provider: 'voyageai', model: 'voyage-3', input: 5, operation: 'embeddings');

        return 'embedded';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
