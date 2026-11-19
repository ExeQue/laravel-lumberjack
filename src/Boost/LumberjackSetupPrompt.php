<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Boost;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;

/**
 * The lumberjack-setup skill as a prompt of Laravel Boost's MCP server, so an assistant
 * can run it as soon as the package is installed, without installing the skill first.
 * Only loaded when Boost is installed: it extends Laravel MCP's Prompt.
 */
class LumberjackSetupPrompt extends Prompt
{
    public const string SKILL = __DIR__.'/../../resources/boost/skills/lumberjack-setup/SKILL.md';

    protected string $name = 'lumberjack-setup';

    protected string $title = 'lumberjack_setup';

    protected string $description = 'Sets Lumberjack up to fit the app: looks at what it runs with and how it is deployed, runs lumberjack:install and sets only the keys its stack calls for.';

    public function handle(): Response
    {
        // The skill without its frontmatter: one text for both.
        return Response::text((string) preg_replace('/\A---\n.*?\n---\n+/s', '', (string) file_get_contents(self::SKILL)));
    }
}
