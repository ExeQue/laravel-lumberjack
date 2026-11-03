<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * One check as Lumberjack last said it stands.
 */
final readonly class HealthStateSnapshot
{
    public function __construct(
        public string $slug,
        public HealthState $state,
        public ?CarbonImmutable $since = null,
        public ?string $message = null,
    ) {
    }

    /**
     * @param  array<mixed>  $check  one entry of the server's health.checks, or toArray()
     */
    public static function fromArray(string $slug, array $check): self
    {
        try {
            $since = is_string($check['since'] ?? null) ? CarbonImmutable::parse($check['since']) : null;
        } catch (Throwable) {
            $since = null;
        }

        return new self(
            $slug,
            HealthState::tryFrom(is_string($check['state'] ?? null) ? $check['state'] : '') ?? HealthState::Unknown,
            $since,
            is_string($check['message'] ?? null) ? $check['message'] : null,
        );
    }

    /**
     * @return array{state: string, since: string|null, message: string|null}
     */
    public function toArray(): array
    {
        return ['state' => $this->state->value, 'since' => $this->since?->toIso8601String(), 'message' => $this->message];
    }
}
