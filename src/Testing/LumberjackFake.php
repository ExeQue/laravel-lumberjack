<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing;

use Closure;
use ExeQue\Lumberjack\Recorder;
use PHPUnit\Framework\Assert;

/**
 * Asserts on the events the app would have sent to Lumberjack, as they would have been
 * sent: scrubbed and sampled. Exceptions and custom alerts have assertions of their own
 * on top.
 *
 * Without a $times, an assertion wants at least one.
 */
class LumberjackFake
{
    private const int MAX_LISTED = 10;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly FakeAgentClient $client,
    ) {
    }

    /**
     * The events recorded so far.
     *
     * @param  array<string, mixed>|(Closure(array<string, mixed>): bool)|null  $matching  values by key (dot notation), or a check of each event
     * @return list<array<string, mixed>>
     */
    public function recorded(?EventType $type = null, array|Closure|null $matching = null): array
    {
        $this->recorder->flush();

        $events = array_merge([], ...array_map(fn (array $payload): array => $payload['events'], $this->client->payloads));

        return array_values(array_filter($events, fn (array $event): bool => ($type === null || $event['type'] === $type->value) && $this->matches($event, $matching)));
    }

    /**
     * @param  array<string, mixed>|(Closure(array<string, mixed>): bool)|null  $matching  values by key (dot notation), or a check of each event
     */
    public function assertRecorded(EventType $type, array|Closure|null $matching = null, ?int $times = null): void
    {
        $this->assertFound(count($this->recorded($type, $matching)), $times, $this->describe($type, $matching), $this->listing($this->recorded()));
    }

    /**
     * @param  array<string, mixed>|(Closure(array<string, mixed>): bool)|null  $matching  values by key (dot notation), or a check of each event
     */
    public function assertNotRecorded(EventType $type, array|Closure|null $matching = null): void
    {
        $found = $this->recorded($type, $matching);

        Assert::assertEmpty($found, "Expected no {$this->describe($type, $matching)}.".$this->listing($found));
    }

    public function assertNothingRecorded(): void
    {
        $found = $this->recorded();

        Assert::assertEmpty($found, 'Expected nothing to be recorded.'.$this->listing($found));
    }

    /**
     * @param  class-string  $exception  this class or one extending it
     */
    public function assertReported(string $exception, ?string $message = null, ?int $times = null): void
    {
        $this->assertFound(count($this->reported($exception, $message)), $times, $this->describeReport($exception, $message), $this->listing($this->recorded(EventType::Exception)));
    }

    /**
     * @param  class-string  $exception  this class or one extending it
     */
    public function assertNotReported(string $exception, ?string $message = null): void
    {
        $found = $this->reported($exception, $message);

        Assert::assertEmpty($found, "Expected no {$this->describeReport($exception, $message)}.".$this->listing($found));
    }

    /**
     * The custom alerts sent so far.
     *
     * @param  string|(Closure(RecordedAlert): bool)|null  $alert  a title, or a check of each alert
     * @return list<RecordedAlert>
     */
    public function alerts(string|Closure|null $alert = null, ?string $topic = null, ?string $level = null): array
    {
        $alerts = array_map(RecordedAlert::fromEvent(...), $this->recorded(EventType::Alert));

        return array_values(array_filter($alerts, fn (RecordedAlert $recorded): bool => match (true) {
            $topic !== null && $recorded->topic !== $topic, $level !== null && $recorded->level !== $level => false,
            $alert instanceof Closure => $alert($recorded),
            $alert !== null => $recorded->title === $alert,
            default => true,
        }));
    }

    /**
     * @param  string|(Closure(RecordedAlert): bool)|null  $alert  a title, or a check of each alert
     */
    public function assertAlerted(string|Closure|null $alert = null, ?string $topic = null, ?string $level = null, ?int $times = null): void
    {
        $this->assertFound(count($this->alerts($alert, $topic, $level)), $times, $this->describeAlert($alert, $topic, $level), $this->alertListing($this->alerts()));
    }

    /**
     * @param  string|(Closure(RecordedAlert): bool)|null  $alert  a title, or a check of each alert
     */
    public function assertNotAlerted(string|Closure|null $alert = null, ?string $topic = null, ?string $level = null): void
    {
        $found = $this->alerts($alert, $topic, $level);

        Assert::assertEmpty($found, "Expected no {$this->describeAlert($alert, $topic, $level)}.".$this->alertListing($found));
    }

    public function assertNothingAlerted(): void
    {
        $this->assertNotAlerted();
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>|(Closure(array<string, mixed>): bool)|null  $matching
     */
    private function matches(array $event, array|Closure|null $matching): bool
    {
        if ($matching instanceof Closure) {
            return $matching($event);
        }

        foreach ($matching ?? [] as $key => $value) {
            if (data_get($event, $key) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  class-string  $exception
     * @return list<array<string, mixed>>
     */
    private function reported(string $exception, ?string $message): array
    {
        return $this->recorded(EventType::Exception, fn (array $event): bool => is_a($event['class'], $exception, true) && ($message === null || $event['message'] === $message));
    }

    private function assertFound(int $found, ?int $times, string $what, string $listing): void
    {
        if ($times === null) {
            Assert::assertGreaterThan(0, $found, "Expected {$what}, but found none.".$listing);

            return;
        }

        Assert::assertSame($times, $found, "Expected {$what} {$times} time(s), but found {$found}.".$listing);
    }

    /**
     * @param  array<string, mixed>|Closure|null  $matching
     */
    private function describe(EventType $type, array|Closure|null $matching): string
    {
        return match (true) {
            $matching instanceof Closure => "a [{$type->value}] event passing the check",
            $matching !== null => "a [{$type->value}] event matching ".json_encode($matching),
            default => "a [{$type->value}] event",
        };
    }

    private function describeReport(string $exception, ?string $message): string
    {
        return "a reported [{$exception}]".($message === null ? '' : " with the message \"{$message}\"");
    }

    private function describeAlert(string|Closure|null $alert, ?string $topic, ?string $level): string
    {
        $parts = array_filter([
            $alert instanceof Closure ? 'passing the check' : ($alert === null ? null : "titled \"{$alert}\""),
            $topic === null ? null : "on topic \"{$topic}\"",
            $level === null ? null : "at level \"{$level}\"",
        ]);

        return trim('a custom alert '.implode(', ', $parts));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function listing(array $events): string
    {
        return $this->list('Recorded', array_map(fn (array $event): string => match ($event['type']) {
            'exception' => "exception: {$event['class']}: {$event['message']}",
            'log' => 'log: '.($event['message'] ?? ''),
            'alert' => "alert: {$event['title']}",
            default => (string) $event['type'],
        }, $events));
    }

    /**
     * @param  list<RecordedAlert>  $alerts
     */
    private function alertListing(array $alerts): string
    {
        return $this->list('Sent', array_map(fn (RecordedAlert $alert): string => "\"{$alert->title}\" (topic: {$alert->topic}, level: {$alert->level})", $alerts));
    }

    /**
     * @param  list<string>  $lines
     */
    private function list(string $heading, array $lines): string
    {
        if ($lines === []) {
            return "\n{$heading}: nothing.";
        }

        $more = count($lines) - self::MAX_LISTED;

        return "\n{$heading}:\n  ".implode("\n  ", array_slice($lines, 0, self::MAX_LISTED)).($more > 0 ? "\n  and {$more} more" : '');
    }
}
