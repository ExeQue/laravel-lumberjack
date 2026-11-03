<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health;

final class CheckResult
{
    /**
     * @param  array<string, int|float>  $metrics  e.g. latency_ms, used_percent
     */
    public function __construct(
        public readonly CheckStatus $status,
        public readonly ?string $message = null,
        public readonly array $metrics = [],
        public readonly ?float $durationMs = null,
    ) {
    }

    /**
     * @param  array<string, int|float>  $metrics
     */
    public static function ok(?string $message = null, array $metrics = []): self
    {
        return new self(CheckStatus::Ok, $message, $metrics);
    }

    /**
     * @param  array<string, int|float>  $metrics
     */
    public static function warning(?string $message = null, array $metrics = []): self
    {
        return new self(CheckStatus::Warning, $message, $metrics);
    }

    /**
     * @param  array<string, int|float>  $metrics
     */
    public static function failed(?string $message = null, array $metrics = []): self
    {
        return new self(CheckStatus::Failed, $message, $metrics);
    }

    public function withDuration(float $durationMs): self
    {
        return new self($this->status, $this->message, $this->metrics, $durationMs);
    }

    /**
     * Ok, a warning past $warnAt or failed past $failAt (both inclusive, null for never).
     *
     * @param  array<string, int|float>  $metrics
     */
    public static function threshold(int|float $value, ?int $warnAt, ?int $failAt, string $message, array $metrics = []): self
    {
        return match (true) {
            $failAt !== null && $value >= $failAt => self::failed($message, $metrics),
            $warnAt !== null && $value >= $warnAt => self::warning($message, $metrics),
            default => self::ok($message, $metrics),
        };
    }
}
