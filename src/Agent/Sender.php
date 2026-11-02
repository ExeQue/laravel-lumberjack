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

    public function heartbeat(string $host, string $version, ?string $name = null): bool
    {
        try {
            return $this->http->withToken($this->token)->timeout(self::TIMEOUT)
                ->post($this->url.'/api/ingest/heartbeat', array_filter(['host' => $host, 'version' => $version, 'name' => $name]))
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
