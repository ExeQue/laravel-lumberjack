<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing;

/**
 * A custom alert as Lumberjack would receive it, with the server's defaults filled in.
 */
final readonly class RecordedAlert
{
    /**
     * @param  list<array{name: string, value: string, inline: bool}>  $fields
     * @param  list<array{title: ?string, headers: list<string>, rows: list<list<string>>}>  $tables
     * @param  list<array{label: string, url: string, style: ?string}>  $links
     */
    public function __construct(
        public string $title,
        public string $topic,
        public string $level,
        public ?string $message,
        public ?string $url,
        public array $fields,
        public array $tables,
        public array $links,
        public ?string $dedupeKey,
        public ?int $dedupeMinutes,
    ) {
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function fromEvent(array $event): self
    {
        return new self(
            title: $event['title'],
            topic: $event['topic'] ?? 'default',
            level: $event['level'] ?? 'info',
            message: $event['message'] ?? null,
            url: $event['url'] ?? null,
            fields: $event['fields'] ?? [],
            tables: $event['tables'] ?? [],
            links: $event['links'] ?? [],
            dedupeKey: $event['dedupe_key'] ?? null,
            dedupeMinutes: $event['dedupe_minutes'] ?? null,
        );
    }
}
