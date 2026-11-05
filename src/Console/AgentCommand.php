<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Console;

use ExeQue\Lumberjack\Health\HealthStates;
use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Support\Client as ClientInfo;
use ExeQue\Lumberjack\Support\QueueSizes;
use ExeQue\Lumberjack\Support\QueueWorkers;
use ExeQue\LumberjackAgent\Agent;
use ExeQue\LumberjackAgent\Exceptions\CannotListenException;
use ExeQue\LumberjackAgent\HostMetrics;
use ExeQue\LumberjackAgent\ProjectFingerprint;
use ExeQue\LumberjackAgent\Sender;
use ExeQue\LumberjackAgent\Server;
use ExeQue\LumberjackAgent\Spool;
use GuzzleHttp\Client as Guzzle;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Http\Client\Factory as Http;
use Symfony\Component\Console\Input\InputOption;

/**
 * The local agent (exeque/lumberjack-agent), run by artisan: listens on 127.0.0.1 for
 * batches from this app and sends them on to Lumberjack, with the app's queue sizes and
 * the health states for its checks on top. Run it under Supervisor or systemd.
 */
class AgentCommand extends Command
{
    protected $name = 'lumberjack:agent';

    protected $description = 'Run the local Lumberjack agent';

    private bool $running = true;

    public function handle(Recorder $recorder, Http $http, HealthStates $states): int
    {
        // The agent must never report on itself.
        $recorder->disable();

        if (! (bool) config('lumberjack.enabled', true)) {
            return $this->idle();
        }

        $url = (string) config('lumberjack.url');
        $token = (string) config('lumberjack.token');

        if ($url === '' || $token === '') {
            $this->components->error('Set LUMBERJACK_URL and LUMBERJACK_TOKEN first.');

            return self::FAILURE;
        }

        $host = (string) ($this->option('host') ?? config('lumberjack.agent.host'));
        $port = (int) ($this->option('port') ?? config('lumberjack.agent.port'));
        $warn = fn (string $message) => $this->components->warn($message);

        $queues = new QueueSizes(
            $this->laravel->make(QueueFactory::class),
            array_values(array_filter((array) config('lumberjack.queues', []), is_string(...))),
            workers: $this->laravel->make(QueueWorkers::class),
        );

        $metrics = new HostMetrics(base_path());

        $agent = new Agent(
            // Through Laravel's HTTP stack, so its global middleware and Http::fake() apply.
            new Sender($url, $token, max(1, (int) config('lumberjack.agent.send_timeout', Sender::TIMEOUT)), new Guzzle(['handler' => $http->buildHandlerStack()])),
            new Spool((string) config('lumberjack.agent.spool_path'), (int) config('lumberjack.agent.spool_max_bytes'), $warn),
            (int) config('lumberjack.agent.interval', 5),
            gethostname() ?: null,
            $this->agentName(),
            ProjectFingerprint::of($token),
            $warn,
            // The environment, so the heartbeat's answer holds the health states of this app's.
            fn (): array => ['environment' => config('lumberjack.environment'), 'queues' => $queues->measure(), 'metrics' => $metrics->measure()],
            fn (array $health) => $states->fill($health, fromAgent: true),
            ProjectFingerprint::agentKey($token, is_string(config('lumberjack.agent.key')) ? config('lumberjack.agent.key') : null),
            ClientInfo::describe(),
        );

        $server = new Server($agent, $host, $port, (int) ($this->option('memory') ?? config('lumberjack.agent.memory_limit', 128)), $warn);

        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT, SIGQUIT], fn () => $server->stop());
        }

        $this->components->info(($this->agentName() !== null ? "Lumberjack agent {$this->agentName()}" : 'Lumberjack agent')." listening on {$host}:{$port}, sending to {$url}.");

        try {
            $server->run();
        } catch (CannotListenException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Turned off: nothing to send, but a process manager would restart an agent that
     * exits over and over, so it waits to be stopped.
     */
    private function idle(): int
    {
        $this->components->warn('Lumberjack is disabled (LUMBERJACK_ENABLED=false); the agent sends nothing and waits to be stopped.');

        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT, SIGQUIT], function (): void {
                $this->running = false;
            });
        }

        while ($this->running) {
            sleep(1);
        }

        return self::SUCCESS;
    }

    private function agentName(): ?string
    {
        $name = $this->option('name') ?? config('lumberjack.agent.name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * @return list<InputOption>
     */
    protected function getOptions(): array
    {
        return [
            new InputOption('host', null, InputOption::VALUE_REQUIRED, 'Address to listen on (default from config)'),
            new InputOption('port', null, InputOption::VALUE_REQUIRED, 'Port to listen on (default from config)'),
            new InputOption('name', null, InputOption::VALUE_REQUIRED, 'What Lumberjack lists this agent as (default: the host name)'),
            new InputOption('memory', null, InputOption::VALUE_REQUIRED, 'Exit past this many MB, so the process manager starts a fresh agent (0 = no limit)'),
        ];
    }
}
