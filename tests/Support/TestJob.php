<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

class TestJob extends Job implements JobContract
{
    public function __construct(
        private readonly string $uuid = '9f1c6b3e-0000-4000-8000-000000000001',
        private readonly int $attempt = 1,
    ) {
        $this->queue = 'emails';
        $this->connectionName = 'redis';
    }

    public function getJobId(): string
    {
        return '1';
    }

    public function getRawBody(): string
    {
        return (string) json_encode(['uuid' => $this->uuid, 'displayName' => 'App\\Jobs\\SendInvoice', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'maxTries' => 3, 'timeout' => 60]);
    }

    public function attempts(): int
    {
        return $this->attempt;
    }
}
