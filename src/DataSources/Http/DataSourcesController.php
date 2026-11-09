<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources\Http;

use Carbon\CarbonImmutable;
use ExeQue\Lumberjack\DataSources\DataRange;
use ExeQue\Lumberjack\DataSources\DataSourceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What Lumberjack pulls. Without only, which sources there are; nothing is resolved for
 * that. With only, that source for the range from and to (Unix times), one per call so a
 * slow one costs nobody else.
 */
final class DataSourcesController
{
    public const int FORMAT_VERSION = 1;

    public const int MAX_SOURCES = 1;

    public const int MAX_RANGE_SECONDS = 34_560_000;

    private const int MAX_TIME_DIGITS = 11;

    public function __invoke(Request $request, DataSourceResolver $resolver): JsonResponse
    {
        if (! $request->query->has('only')) {
            return new JsonResponse(['version' => self::FORMAT_VERSION, ...$resolver->index()]);
        }

        $text = fn (string $name): string => is_array($value = $request->query($name)) ? abort(400) : (string) $value;
        $from = $text('from');
        $to = $text('to');
        $keys = array_values(array_unique(array_filter(explode(',', $text('only')), fn (string $key): bool => $key !== '')));

        abort_unless(ctype_digit($from) && ctype_digit($to) && strlen($to) <= self::MAX_TIME_DIGITS && (int) $from < (int) $to && (int) $to - (int) $from <= self::MAX_RANGE_SECONDS && count($keys) === self::MAX_SOURCES, 400);

        $range = new DataRange(CarbonImmutable::createFromTimestampUTC((int) $from), CarbonImmutable::createFromTimestampUTC((int) $to));

        return new JsonResponse([
            'version' => self::FORMAT_VERSION,
            'sources' => (object) $resolver->resolve($keys, $range),
        ]);
    }
}
