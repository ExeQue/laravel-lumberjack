<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Agent;

use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Talks to the Lumberjack server.
 */
class Sender
{
    public const int TIMEOUT = 10;

    public function __construct(
        private readonly Http $http,
        private readonly string $url,
        private readonly string $token,
    ) {
    }

    public function send(string $gzippedBody): SendResult
    {
        try {
            $response = $this->http
                ->withToken($this->token)
                ->withHeaders(['Content-Encoding' => 'gzip'])
                ->timeout(self::TIMEOUT)
                ->withBody($gzippedBody, 'application/json')
                ->post($this->url.'/api/ingest');
        } catch (Throwable) {
            return SendResult::Retry;
        }

        if ($response->successful()) {
            return SendResult::Sent;
        }

        // A batch the server refuses as such will never succeed; anything else might.
        return $response->clientError() && ! in_array($response->status(), [401, 408, 429], true)
            ? SendResult::Rejected
            : SendResult::Retry;
    }

    /**
     * @param  array<string, array{name: string, version: string}>  $client  what the agent is, see Client
     * @param  array<string, mixed>  $status  e.g. queue sizes
     * @return array<mixed>|null the server's answer, e.g. the health states; null when it failed
     */
    public function heartbeat(string $host, array $client, ?string $name = null, array $status = []): ?array
    {
        try {
            $response = $this->http->withToken($this->token)->timeout(self::TIMEOUT)
                // version too, for servers from before agents described themselves.
                ->post($this->url.'/api/ingest/heartbeat', [...$status, ...array_filter(['host' => $host, 'client' => $client, 'version' => $client['sdk']['version'] ?? null, 'name' => $name])]);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();

            return is_array($body) ? $body : [];
        } catch (Throwable) {
            return null;
        }
    }
}
