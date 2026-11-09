<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources;

/**
 * How Lumberjack proves a pull is its own: an HMAC with the pull token, which itself never
 * travels. The host is signed too, so a signature for one server is worth nothing on
 * another. The server repeats this format; a change needs a new version on both sides.
 */
final class PullSignature
{
    public const string VERSION = 'v1';

    public const string HEADER_TIMESTAMP = 'X-Lumberjack-Timestamp';

    public const string HEADER_SIGNATURE = 'X-Lumberjack-Signature';

    /**
     * @param  array<array-key, mixed>  $query
     */
    public static function sign(string $token, string $method, string $host, string $path, array $query, int $timestamp): string
    {
        ksort($query);
        $canonical = implode("\n", [self::VERSION, strtoupper($method), strtolower($host), $path, http_build_query($query, '', '&', PHP_QUERY_RFC3986), $timestamp]);

        return self::VERSION.'='.hash_hmac('sha256', $canonical, $token);
    }

    /**
     * The headers for a request to $url, for a test or for checking the setup.
     *
     * @param  array<array-key, mixed>  $query
     * @return array<string, string>
     */
    public static function headers(string $token, string $url, array $query, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        return [
            self::HEADER_TIMESTAMP => (string) $timestamp,
            self::HEADER_SIGNATURE => self::sign($token, 'GET', (string) parse_url($url, PHP_URL_HOST), ((string) parse_url($url, PHP_URL_PATH)) ?: '/', $query, $timestamp),
        ];
    }
}
