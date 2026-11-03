<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Console;

use ExeQue\Lumberjack\Agent\Agent;
use ExeQue\Lumberjack\Agent\Sender;
use ExeQue\Lumberjack\Agent\Spool;
use ExeQue\Lumberjack\Health\HealthStates;
use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Support\HostMetrics;
use ExeQue\Lumberjack\Support\ProjectFingerprint;
use ExeQue\Lumberjack\Support\QueueSizes;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Http\Client\Factory as Http;
use Symfony\Component\Console\Input\InputOption;

/**
 * The local agent: listens on 127.0.0.1 for batches from this app and sends
 * them on to Lumberjack. Run it under Supervisor or systemd.
 */
class AgentCommand extends Command
{
    public const int MAX_CLIENT_BUFFER = 16 * 1024 * 1024;

    public const int BACKLOG = 1024;

    protected $name = 'lumberjack:agent';

    protected $description = 'Run the local Lumberjack agent';

    private bool $running = true;

    public function handle(Recorder $recorder, Http $http, HealthStates $states): int
    {
        // The agent must never report on itself.
        $recorder->disable();

        $url = (string) config('lumberjack.url');
        $token = (string) config('lumberjack.token');

        if ($url === '' || $token === '') {
            $this->components->error('Set LUMBERJACK_URL and LUMBERJACK_TOKEN first.');

            return self::FAILURE;
        }

        $memoryLimit = (int) ($this->option('memory') ?? config('lumberjack.agent.memory_limit', 128));
        $host = (string) ($this->option('host') ?? config('lumberjack.agent.host'));
        $port = (int) ($this->option('port') ?? config('lumberjack.agent.port'));

        // A deep backlog: while the agent sends, apps still connect and write into the
        // kernel's buffer instead of timing out.
        $server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $error, context: stream_context_create(['socket' => ['backlog' => self::BACKLOG]]));

        if ($server === false) {
            $this->components->error("Cannot listen on {$host}:{$port}: {$error}");

            return self::FAILURE;
        }

        stream_set_blocking($server, false);

        $queues = new QueueSizes($this->laravel->make(QueueFactory::class), array_values(array_filter((array) config('lumberjack.queues', []), is_string(...))));

        $metrics = new HostMetrics(base_path());

        $agent = new Agent(
            new Sender($http, $url, $token, max(1, (int) config('lumberjack.agent.send_timeout', Sender::TIMEOUT))),
            new Spool((string) config('lumberjack.agent.spool_path'), (int) config('lumberjack.agent.spool_max_bytes')),
            (int) config('lumberjack.agent.interval', 5),
            gethostname() ?: null,
            $this->agentName(),
            ProjectFingerprint::of($token),
            fn (string $message) => $this->components->warn($message),
            // The environment, so the heartbeat's answer holds the health states of this app's.
            fn (): array => ['environment' => config('lumberjack.environment'), 'queues' => $queues->measure(), 'metrics' => $metrics->measure()],
            fn (array $health) => $states->fill($health),
        );

        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT, SIGQUIT], function (): void {
                $this->running = false;
            });
        }

        $this->components->info(($this->agentName() !== null ? "Lumberjack agent {$this->agentName()}" : 'Lumberjack agent')." listening on {$host}:{$port}, sending to {$url}.");

        $this->loop($server, $agent, $memoryLimit);

        $agent->flush(microtime(true));
        fclose($server);

        return self::SUCCESS;
    }

    /**
     * @param  resource  $server
     */
    private function loop($server, Agent $agent, int $memoryLimit): void
    {
        /** @var array<int, resource> $clients */
        $clients = [];
        /** @var array<int, string> $buffers */
        $buffers = [];

        while ($this->running) {
            $read = [$server, ...array_values($clients)];
            $write = $except = null;

            if (@stream_select($read, $write, $except, 1) === false) {
                $read = [];
            }

            foreach ($read as $stream) {
                if ($stream === $server) {
                    $client = @stream_socket_accept($server, 0);

                    if ($client !== false) {
                        stream_set_blocking($client, false);
                        $clients[(int) $client] = $client;
                        $buffers[(int) $client] = '';
                    }

                    continue;
                }

                $id = (int) $stream;
                $chunk = fread($stream, 65536);

                if ($chunk !== false && $chunk !== '') {
                    $buffers[$id] .= $chunk;

                    while (($pos = strpos($buffers[$id], "\n")) !== false) {
                        $agent->receive(substr($buffers[$id], 0, $pos));
                        $buffers[$id] = substr($buffers[$id], $pos + 1);
                    }
                }

                if (feof($stream) || strlen($buffers[$id]) > self::MAX_CLIENT_BUFFER) {
                    fclose($stream);
                    unset($clients[$id], $buffers[$id]);
                }
            }

            $agent->tick(microtime(true));

            if ($agent->stopRequested()) {
                $this->components->info('Asked to terminate; sending what is left and exiting.');
                $this->running = false;
            }

            if ($memoryLimit > 0 && memory_get_usage(true) > $memoryLimit * 1024 * 1024) {
                $this->components->warn("Memory passed {$memoryLimit} MB; sending what is left and exiting.");
                $this->running = false;
            }
        }
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
