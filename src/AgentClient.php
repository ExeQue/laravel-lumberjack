<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack;

/**
 * Hands a batch to the local agent. Never blocks the app for long and never
 * throws: when the agent is not there, the batch is dropped.
 *
 * After a failed send it stops trying for a few seconds (a circuit breaker), so a
 * long-running worker does not pay the connect timeout on every job while the agent
 * is down or stuck. What was dropped meanwhile is reported, as a warning log line,
 * with the first batch the agent takes again.
 */
class AgentClient
{
    public const float CONNECT_TIMEOUT = 0.05;

    /** For the whole batch, not per write. */
    public const float WRITE_TIMEOUT = 1.0;

    public const int BREAK_SECONDS = 5;

    private float $closedUntil = 0.0;

    private int $dropped = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $breakSeconds = self::BREAK_SECONDS,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): bool
    {
        $now = microtime(true);

        if ($now < $this->closedUntil) {
            $this->dropped++;

            return false;
        }

        if ($this->dropped > 0 && is_array($payload['events'] ?? null)) {
            $payload['events'][] = [
                'type' => 'log',
                'level' => 'warning',
                'message' => "Lumberjack dropped {$this->dropped} batches while the agent could not be reached.",
                'trace_id' => bin2hex(random_bytes(16)),
                'timestamp' => $now,
            ];
        }

        $line = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES, 64);

        if ($line === false) {
            return false;
        }

        if (! $this->write($line)) {
            $this->closedUntil = microtime(true) + $this->breakSeconds;
            $this->dropped++;

            return false;
        }

        $this->dropped = 0;

        return true;
    }

    private function write(string $line): bool
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, self::CONNECT_TIMEOUT);

        if ($socket === false) {
            return false;
        }

        $data = $line."\n";
        $deadline = microtime(true) + self::WRITE_TIMEOUT;

        while ($data !== '') {
            $left = $deadline - microtime(true);

            if ($left <= 0) {
                fclose($socket);

                return false;
            }

            stream_set_timeout($socket, (int) $left, (int) (($left - (int) $left) * 1e6));
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
