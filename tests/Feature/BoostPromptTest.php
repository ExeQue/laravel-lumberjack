<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Boost\LumberjackSetupPrompt;
use Laravel\Boost\Mcp\Boost;

// Without Boost the prompt's parent cannot load, and the tests skip themselves.
if (class_exists(Boost::class)) {
    covers(LumberjackSetupPrompt::class);
}

beforeEach(function () {
    if (! class_exists(Boost::class)) {
        $this->markTestSkipped('laravel/boost is not installed.');
    }
});

describe('the setup prompt on Boost\'s MCP server', function () {
    it('is there without installing the skill', function () {
        Boost::prompts()->assertRegistered(LumberjackSetupPrompt::class);
    });

    it('is the setup skill without its frontmatter', function () {
        Boost::prompt(LumberjackSetupPrompt::class)
            ->assertOk()
            ->assertSee('# Lumberjack setup')
            ->assertSee('php artisan lumberjack:install --no-interaction')
            ->assertDontSee('name: lumberjack-setup');
    });

    it('keeps the prompts an app includes itself', function () {
        $this->withConfig(['boost.mcp.prompts.include' => ['App\\Mcp\\OwnPrompt']]);

        expect(config('boost.mcp.prompts.include'))->toBe(['App\\Mcp\\OwnPrompt', LumberjackSetupPrompt::class]);
    });
});
