<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Activity;

/**
 * Who did something, as an object of the app says it should be known to Lumberjack: a type
 * and a key, such as ("user", 17) or ("api-client", "billing").
 */
final readonly class Actor
{
    public string $id;

    public function __construct(
        public string $type,
        string|int $id,
    ) {
        $this->id = (string) $id;
    }
}
