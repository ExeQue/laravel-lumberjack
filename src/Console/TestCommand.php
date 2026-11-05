<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Console;

use ExeQue\Lumberjack\Recorder;
use ExeQue\LumberjackAgent\AgentClient;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Sends a test exception through the agent, to check an install end to end.
 */
class TestCommand extends Command
{
    protected $name = 'lumberjack:test';

    protected $description = 'Send a test exception to Lumberjack through the local agent';

    public function handle(Recorder $recorder, AgentClient $client): int
    {
        if (! $recorder->enabled()) {
            $this->components->error('Lumberjack is disabled (LUMBERJACK_ENABLED=false).');

            return self::FAILURE;
        }

        $sent = $client->send(['v' => 1, 'events' => []]);

        if (! $sent) {
            $this->components->error(sprintf('No agent on %s:%d. Start it with: php artisan lumberjack:agent', config('lumberjack.agent.host'), config('lumberjack.agent.port')));

            return self::FAILURE;
        }

        $recorder->exception(new RuntimeException('This is a test exception from php artisan lumberjack:test.'));
        $recorder->flush();

        $this->components->info('Test exception handed to the agent. It shows up in Lumberjack within a few seconds.');

        return self::SUCCESS;
    }
}
