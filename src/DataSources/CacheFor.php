<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources;

use Attribute;

/**
 * Keeps what a data source resolves, per range, in the app's default cache.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class CacheFor
{
    public function __construct(
        public int $seconds,
    ) {
    }
}
