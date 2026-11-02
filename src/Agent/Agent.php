<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Agent;

use Closure;
use ExeQue\Lumberjack\Recorder;

/**
 * Collects batches from one app's processes on this server and sends them on
 * to Lumberjack with that app's token: every few seconds or at 1 MB, with retry
 * and a disk spool.
 *
 * One agent serves one project. Several apps on a server each run their own
 * agent on their own port; a batch from an app of another project (one that
 * points at the wrong port) is turned away, not sent under this token.
 */
class Agent
{
    public const int FLUSH_BYTES = 1_048_576;

    public const int EVENTS_PER_BATCH = 1000;

    public const int HEARTBEAT_SECONDS = 60;

    public const int MAX_BACKOFF = 300;

    /** @var array<string, array{envelope: array<string, mixed>, events: list<mixed>}> */
    private array $batches = [];

    private int $bytes = 0;

    private float $lastFlush;

    private float $lastHeartbeat = 0.0;

    private float $retryAt = 0.0;

    private int $failures = 0;

    private bool $stopRequested = false;

    /** @var array<string, true> Projects already warned about. */
    private array $foreign = [];

    public function __construct(
        private readonly Sender $sender,
        private readonly Spool $spool,
        private readonly int $interval = 5,
        private readonly ?string $host = null,
        private readonly ?string $name = null,
        private readonly ?string $project = null,
        private readonly ?Closure $warn = null,
    ) {
        $this->lastFlush = microtime(true);
    }

    /**
     * One newline-delimited payload from an app.
     */
    public function receive(string $line): void
    {
        $payload = json_decode($line, true);

        // php artisan lumberjack:terminate, e.g. after a deploy.
        if (is_array($payload) && ($payload['control'] ?? null) === 'terminate') {
            $this->stopRequested = true;

            return;
        }

        if (! is_array($payload) || ! is_array($payload['events'] ?? null)) {
            return;
        }

        // Without a mark on either side (an older SDK, an app without the token) there is nothing to compare.
        $from = is_string($payload['project'] ?? null) ? $payload['project'] : null;

        if ($from !== null && $this->project !== null && ! hash_equals($this->project, $from)) {
            if (! isset($this->foreign[$from])) {
                $this->foreign[$from] = true;
                ($this->warn)?->__invoke("Turned away data from an app of another project (mark {$from}). Give each app its own agent and LUMBERJACK_AGENT_PORT.");
            }

            return;
        }

        $envelope = [
            'v' => 1,
            'sdk' => $payload['sdk'] ?? null,
            'environment' => $payload['environment'] ?? null,
            'release' => $payload['release'] ?? null,
            'host' => $payload['host'] ?? $this->host,
        ];
        $key = md5(serialize($envelope));

        $this->batches[$key] ??= ['envelope' => $envelope, 'events' => []];
        array_push($this->batches[$key]['events'], ...array_values($payload['events']));
        $this->bytes += strlen($line);

        if ($this->bytes >= self::FLUSH_BYTES) {
            $this->flush(microtime(true));
        }
    }

    public function tick(float $now): void
    {
        if ($now - $this->lastFlush >= $this->interval) {
            $this->flush($now);
        }

        $this->retrySpool($now);

        if ($now - $this->lastHeartbeat >= self::HEARTBEAT_SECONDS) {
            $this->lastHeartbeat = $now;
            $this->sender->heartbeat($this->host ?? (gethostname() ?: 'unknown'), Recorder::VERSION, $this->name);
        }
    }

    public function flush(float $now): void
    {
        $this->lastFlush = $now;
        $batches = $this->batches;
        $this->batches = [];
        $this->bytes = 0;

        foreach ($batches as $batch) {
            foreach (array_chunk($batch['events'], self::EVENTS_PER_BATCH) as $events) {
                $body = gzencode((string) json_encode([...$batch['envelope'], 'events' => $events], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES));

                if ($body !== false) {
                    $this->deliver($body, $now);
                }
            }
        }
    }

    public function stopRequested(): bool
    {
        return $this->stopRequested;
    }

    public function backingOff(float $now): bool
    {
        return $now < $this->retryAt;
    }

    private function deliver(string $body, float $now): void
    {
        // While Lumberjack is down, new data waits its turn behind the spool.
        if ($this->backingOff($now) || $this->spool->count() > 0) {
            $this->spool->push($body);

            return;
        }

        if ($this->sender->send($body) === SendResult::Retry) {
            $this->failed($now);
            $this->spool->push($body);

            return;
        }

        $this->failures = 0;
    }

    private function retrySpool(float $now): void
    {
        while (! $this->backingOff($now) && ($file = $this->spool->oldest()) !== null) {
            $body = $this->spool->read($file);

            if ($body !== null && $this->sender->send($body) === SendResult::Retry) {
                $this->failed($now);

                return;
            }

            $this->failures = 0;
            $this->spool->delete($file);
        }
    }

    private function failed(float $now): void
    {
        $this->failures++;
        $this->retryAt = $now + min(self::MAX_BACKOFF, $this->interval * 2 ** ($this->failures - 1));
    }
}
