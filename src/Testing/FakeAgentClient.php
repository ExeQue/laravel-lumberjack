<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing;

use ExeQue\LumberjackAgent\AgentClient;

/**
 * Keeps what would have gone to the agent.
 */
class FakeAgentClient extends AgentClient
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): bool
    {
        $this->payloads[] = $payload;

        return true;
    }
}
