<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\DataSources;

use Attribute;

/**
 * Files a data source under a group in Lumberjack's picker. Each name is a level of one
 * path: #[Group('Events', 'Storage')] is Events > Storage.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Group
{
    /** @var list<string> */
    public array $path;

    public function __construct(string ...$path)
    {
        $this->path = array_values($path);
    }
}
