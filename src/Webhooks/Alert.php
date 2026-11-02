<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Webhooks;

/**
 * One alert Lumberjack sent to this app's webhook.
 */
final readonly class Alert
{
    /**
     * @param  array<string, mixed>  $payload  the whole webhook body, for anything not lifted out here
     */
    public function __construct(
        public Status $status,
        public string $issueId,
        public ?string $reference,
        public string $title,
        public string $message,
        public string $url,
        public ?string $environment,
        public ?int $burst,
        public ?string $note,
        public ?string $by,
        public array $payload,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(Status $status, array $payload): self
    {
        $issue = is_array($payload['issue'] ?? null) ? $payload['issue'] : [];
        $note = is_array($payload['note'] ?? null) ? $payload['note'] : null;
        $change = is_array($payload['status_change'] ?? null) ? $payload['status_change'] : null;

        return new self(
            status: $status,
            issueId: (string) ($issue['id'] ?? ''),
            reference: isset($issue['reference']) ? (string) $issue['reference'] : null,
            title: (string) ($issue['title'] ?? ''),
            message: (string) ($issue['message'] ?? ''),
            url: (string) ($issue['url'] ?? ''),
            environment: isset($payload['environment']) ? (string) $payload['environment'] : null,
            burst: isset($payload['burst']) ? (int) $payload['burst'] : null,
            note: isset($note['body']) ? (string) $note['body'] : null,
            by: isset($change['by']) ? (string) $change['by'] : (isset($note['by']) ? (string) $note['by'] : null),
            payload: $payload,
        );
    }

    /**
     * Whether the issue is this exception class, or a subclass of it.
     */
    public function isException(string $class): bool
    {
        return $this->title === ltrim($class, '\\')
            || (class_exists($this->title) && is_a($this->title, $class, true));
    }
}
