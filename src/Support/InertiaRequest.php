<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What kind of Inertia request a request was, read from the headers Inertia sends and
 * answers with, so Inertia need not be installed. The first page load is an ordinary
 * request; a visit, a partial reload (deferred props) and a prefetch each are their own.
 */
final class InertiaRequest
{
    public const int MAX_PROPS = 50;

    /**
     * @return array{kind: string, context: array{component: string|null, partial: bool, only: list<string>, except: list<string>, reset: list<string>, prefetch: bool, version_mismatch: bool}}|null
     */
    public static function describe(Request $request, Response $response): ?array
    {
        if ($request->header('X-Inertia') === null) {
            return null;
        }

        $only = self::props($request->header('X-Inertia-Partial-Data'));
        $except = self::props($request->header('X-Inertia-Partial-Except'));
        $partial = $request->header('X-Inertia-Partial-Component') !== null && ($only !== [] || $except !== []);
        $prefetch = $request->header('Purpose') === 'prefetch';
        $location = $response->headers->get('X-Inertia-Location');

        return [
            'kind' => match (true) {
                $prefetch => 'inertia-prefetch',
                $partial => 'inertia-partial',
                default => 'inertia',
            },
            'context' => [
                'component' => self::component($request, $response),
                'partial' => $partial,
                'only' => $only,
                'except' => $except,
                'reset' => self::props($request->header('X-Inertia-Reset')),
                'prefetch' => $prefetch,
                // Inertia answers an outdated asset version with a 409 back to the same URL.
                'version_mismatch' => $response->getStatusCode() === 409 && $location !== null && $location === $request->fullUrl(),
            ],
        ];
    }

    /**
     * The page component: from the partial reload's header, or else from the start of the
     * page object, where Inertia puts it first. Never the whole body: it can be large.
     */
    private static function component(Request $request, Response $response): ?string
    {
        $component = $request->header('X-Inertia-Partial-Component');

        if (is_string($component) && $component !== '') {
            return mb_substr($component, 0, 255);
        }

        $content = $response->getContent();

        if (! is_string($content) || preg_match('/^\{"component":("(?:[^"\\\\]|\\\\.){1,255}")/', substr($content, 0, 512), $match) !== 1) {
            return null;
        }

        $component = json_decode($match[1]);

        return is_string($component) ? $component : null;
    }

    /**
     * @return list<string>
     */
    private static function props(mixed $header): array
    {
        if (! is_string($header) || $header === '') {
            return [];
        }

        return array_slice(array_values(array_filter(array_map(fn (string $prop): string => mb_substr(trim($prop), 0, 255), explode(',', $header)), fn (string $prop): bool => $prop !== '')), 0, self::MAX_PROPS);
    }
}
