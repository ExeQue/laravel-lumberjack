<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack;

/**
 * Hands a batch to the local agent. Never blocks the app for long and never
 * throws: when the agent is not there, the batch is dropped.
 */
class AgentClient
{
    public const float CONNECT_TIMEOUT = 0.05;

    public const int WRITE_TIMEOUT = 1;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): bool
    {
        $line = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            return false;
        }

        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, self::CONNECT_TIMEOUT);

        if ($socket === false) {
            return false;
        }

        stream_set_timeout($socket, self::WRITE_TIMEOUT);
        $data = $line."\n";

        while ($data !== '') {
            $written = @fwrite($socket, $data);

            if ($written === false || $written === 0) {
                fclose($socket);

                return false;
            }

            $data = substr($data, $written);
        }

        fclose($socket);

        return true;
    }
}
