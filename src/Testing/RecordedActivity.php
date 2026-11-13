<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing;

/**
 * An activity as Lumberjack would receive it, with the server's defaults filled in.
 */
final readonly class RecordedActivity
{
    /**
     * @param  array{type: string, id: string}|null  $actor
     * @param  array{type: string, id: string}|null  $subject
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(
        public string $name,
        public string $group,
        public ?array $actor,
        public ?array $subject,
        public array $payload,
    ) {
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function fromEvent(array $event): self
    {
        return new self(
            name: $event['name'],
            group: $event['group'] ?? 'default',
            actor: $event['actor'] ?? null,
            subject: $event['subject'] ?? null,
            payload: $event['payload'] ?? [],
        );
    }

    /** Whether the actor is this type and key. */
    public function isBy(string $type, string|int $id): bool
    {
        return $this->actor === ['type' => $type, 'id' => (string) $id];
    }

    /** Whether the subject is this type and key. */
    public function isOn(string $type, string|int $id): bool
    {
        return $this->subject === ['type' => $type, 'id' => (string) $id];
    }
}
