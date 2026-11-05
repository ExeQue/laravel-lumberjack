<?php

declare(strict_types=1);

namespace Tests\Support;

use ExeQue\LumberjackAgent\AgentClient;

class FakeAgentClient extends AgentClient
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    public function send(array $payload): bool
    {
        $this->payloads[] = $payload;

        return true;
    }
}
