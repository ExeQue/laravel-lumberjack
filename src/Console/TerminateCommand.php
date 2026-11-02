<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Console;

use ExeQue\Lumberjack\AgentClient;
use Illuminate\Console\Command;

/**
 * Asks the local agent to send what it has and exit, so Supervisor or systemd
 * starts it again with the new code, as horizon:terminate does after a deploy.
 */
class TerminateCommand extends Command
{
    protected $name = 'lumberjack:terminate';

    protected $description = 'Ask the local Lumberjack agent to send what it has and exit';

    public function handle(AgentClient $client): int
    {
        if (! $client->send(['control' => 'terminate'])) {
            $this->components->warn(sprintf('No agent on %s:%d; nothing to terminate.', config('lumberjack.agent.host'), config('lumberjack.agent.port')));

            return self::SUCCESS;
        }

        $this->components->info('The agent exits once it has sent what it has.');

        return self::SUCCESS;
    }
}
