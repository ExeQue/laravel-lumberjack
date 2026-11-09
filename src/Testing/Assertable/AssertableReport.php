<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing\Assertable;

use ExeQue\Lumberjack\Testing\Assertable\Concerns\ChecksFields;

/** A reported exception as Lumberjack would receive it. */
final class AssertableReport implements AssertableRecord
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

    public function hasMessage(string $message): static
    {
        return $this->has('message', $message);
    }

    public function hasContext(string $key, mixed $value): static
    {
        return $this->has('context.'.$key, $value);
    }
}
