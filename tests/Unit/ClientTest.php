<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Support\Client;
use ExeQue\LumberjackAgent\AgentInfo;
use Illuminate\Foundation\Application;

it('tells Lumberjack it is the Laravel SDK, running the PHP agent, on PHP and Laravel', function () {
    expect(Client::describe())->toBe([
        'sdk' => ['name' => 'lumberjack.php.laravel', 'version' => Client::version()],
        'agent' => ['name' => 'lumberjack-agent-php', 'version' => AgentInfo::version()],
        'runtime' => ['name' => 'php', 'version' => PHP_VERSION],
        'framework' => ['name' => 'laravel', 'version' => Application::VERSION],
    ]);
});
