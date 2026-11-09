<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing\Assertable;

use ExeQue\Lumberjack\Testing\Assertable\Concerns\ChecksFields;

/** An event as Lumberjack would receive it. */
final class AssertableEvent implements AssertableRecord
{
    use ChecksFields;

    /**
     * @param  array<string, mixed>  $event
     */
    public function __construct(
        private readonly array $event,
    ) {
    }

    public function toArray(): array
    {
        return $this->event;
    }
}
