<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources\Http;

use Closure;
use ExeQue\Lumberjack\DataSources\DataSourceEndpoint;
use ExeQue\Lumberjack\DataSources\PullSignature;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Lets only Lumberjack through. Everything else gets the 404 of a path that does not
 * exist, whatever was wrong; a cache that is down refuses too, as replays could not be seen.
 */
final class VerifyPullSignature
{
    /** How far a request's timestamp may be from the app's clock. */
    public const int TOLERANCE_SECONDS = 60;

    public function __construct(
        private readonly CacheFactory $cache,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->verified($request)) {
            // Worded as the framework's own, as a JSON client sees the message.
            throw new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
        }

        return $next($request);
    }

    private function verified(Request $request): bool
    {
        try {
            return $this->signed($request) && $this->firstUse((string) $request->header(PullSignature::HEADER_SIGNATURE));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Checked against the app's own address, which is what Lumberjack was given and signs,
     * rather than the Host a request claims. The query is read raw, as what global
     * middleware makes of it (empty strings to null) is not what was signed.
     */
    private function signed(Request $request): bool
    {
        $token = config('lumberjack.data_sources.pull_token');
        $timestamp = (string) $request->header(PullSignature::HEADER_TIMESTAMP);

        if (! is_string($token) || $token === '' || ! $request->isMethod('GET') || ! $request->secure() || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $url = parse_url(DataSourceEndpoint::url());
        parse_str($request->getQueryString() ?? '', $query);
        $expected = PullSignature::sign($token, 'GET', (string) ($url['host'] ?? ''), $url['path'] ?? '/', $query, (int) $timestamp);

        return hash_equals($expected, (string) $request->header(PullSignature::HEADER_SIGNATURE));
    }

    public static function seenKey(string $signature): string
    {
        return 'lumberjack:pull-seen:'.hash('sha256', $signature);
    }

    private function firstUse(string $signature): bool
    {
        return $this->cache->store()->add(self::seenKey($signature), true, self::TOLERANCE_SECONDS * 2 + 1);
    }
}
