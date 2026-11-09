<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources;

/**
 * The public address of the endpoint, which the address entered in Lumberjack has to match.
 */
final class DataSourceEndpoint
{
    public const string DEFAULT_PATH = 'lumberjack/datasources';

    /**
     * The app's URL and the path. Requests are checked against this rather than the Host they claim.
     */
    public static function url(): string
    {
        return rtrim((string) config('app.url'), '/').'/'.trim(((string) config('lumberjack.data_sources.path')) ?: self::DEFAULT_PATH, '/');
    }
}
