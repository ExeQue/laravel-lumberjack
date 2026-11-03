<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Checks;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class HttpHealthCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly string $url,
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $downAfter = 5,
        private readonly int $status = 200,
        private readonly ?int $maxLatencyMs = null,
        private readonly string $method = 'GET',
        private readonly int $timeout = 10,
        private readonly ?string $slug = null,
        /**
         * Sent with the request, such as an Authorization or a Host header. They stay in
         * the app: neither the headers nor their values are reported.
         *
         * @var array<string, string>
         */
        private readonly array $headers = [],
    ) {
    }

    public function run(): CheckResult
    {
        $start = hrtime(true);

        try {
            $response = Http::timeout($this->timeout)->withHeaders($this->headers)->send($this->method, $this->url);
        } catch (ConnectionException $e) {
            return CheckResult::failed($e->getMessage());
        }

        $metrics = ['latency_ms' => $latency = self::since($start)];

        if ($response->status() !== $this->status) {
            return CheckResult::failed("Answered {$response->status()}, expected {$this->status}.", $metrics);
        }

        return $this->maxLatencyMs !== null && $latency > $this->maxLatencyMs
            ? CheckResult::warning("Took {$latency} ms, more than {$this->maxLatencyMs} ms.", $metrics)
            : CheckResult::ok(metrics: $metrics);
    }

    protected function defaultSlug(): string
    {
        return 'http:'.parse_url($this->url, PHP_URL_HOST).parse_url($this->url, PHP_URL_PATH);
    }
}
