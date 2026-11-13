<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing;

use Closure;
use ExeQue\Lumberjack\Activity\ActivityActors;
use ExeQue\Lumberjack\Activity\Reference;
use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Testing\Assertable\AssertableAlert;
use ExeQue\Lumberjack\Testing\Assertable\AssertableEvent;
use ExeQue\Lumberjack\Testing\Assertable\AssertableRecord;
use ExeQue\Lumberjack\Testing\Assertable\AssertableReport;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use ReflectionFunction;
use ReflectionNamedType;

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
        private readonly ActivityActors $actors,
    ) {
    }

    /**
     * The activities recorded so far.
     *
     * @param  string|(Closure(RecordedActivity): bool)|null  $activity  a name, or a check of each activity
     * @return list<RecordedActivity>
     */
    public function activities(string|Closure|null $activity = null, ?string $group = null): array
    {
        $activities = array_map(RecordedActivity::fromEvent(...), $this->recorded(EventType::Activity));

        return array_values(array_filter($activities, fn (RecordedActivity $recorded): bool => match (true) {
            $group !== null && $recorded->group !== $group => false,
            $activity instanceof Closure => (bool) $activity($recorded),
            $activity !== null => $recorded->name === $activity,
            default => true,
        }));
    }

    /**
     * @param  string|(Closure(RecordedActivity): bool)|null  $activity  a name, or a check of each activity
     */
    public function assertActivityRecorded(string|Closure|null $activity = null, ?string $group = null, ?int $times = null): void
    {
        $this->assertFound(count($this->activities($activity, $group)), $times, $this->describeActivity($activity, $group), $this->activityListing($this->activities()));
    }

    /**
     * @param  string|(Closure(RecordedActivity): bool)|null  $activity  a name, or a check of each activity
     */
    public function assertActivityNotRecorded(string|Closure|null $activity = null, ?string $group = null): void
    {
        $found = $this->activities($activity, $group);

        Assert::assertEmpty($found, "Expected no {$this->describeActivity($activity, $group)}.".$this->activityListing($found));
    }

    /**
     * The actors the app asked Lumberjack to forget.
     *
     * @return list<Reference>
     */
    public function forgottenActors(): array
    {
        return $this->actors->forgotten();
    }

    public function assertActorForgotten(Model|string $actor, string|int|null $id = null): void
    {
        $wanted = Reference::of($actor, $id);
        $forgotten = array_map(fn (Reference $r): string => "{$r->type}:{$r->id}", $this->forgottenActors());

        Assert::assertContains($wanted !== null ? "{$wanted->type}:{$wanted->id}" : '', $forgotten, 'Expected the actor to be forgotten.'.$this->list('Forgotten', $forgotten));
    }

    /**
     * The events recorded so far.
     *
     * @param  array<string, mixed>|(Closure(array<string, mixed>): bool)|(Closure(AssertableEvent): mixed)|null  $matching  values by key (dot notation), a check of each event, or assertions typed AssertableEvent
     * @return list<array<string, mixed>>
     */
    public function recorded(?EventType $type = null, array|Closure|null $matching = null): array
    {
        $this->recorder->flush();

        $events = array_merge([], ...array_map(fn (array $payload): array => $payload['events'], $this->client->payloads));

        return array_values(array_filter($events, fn (array $event): bool => ($type === null || $event['type'] === $type->value) && $this->matches($event, $matching)));
    }

    /**
     * @param  array<string, mixed>|(Closure(array<string, mixed>): bool)|(Closure(AssertableEvent): mixed)|null  $matching  values by key (dot notation), a check of each event, or assertions typed AssertableEvent
     */
    public function assertRecorded(EventType $type, array|Closure|null $matching = null, ?int $times = null): void
    {
        $this->assertFound(count($this->recorded($type, $matching)), $times, $this->describe($type, $matching), self::isAssertable($matching) ? $this->closest($this->recorded($type), $matching) : $this->listing($this->recorded()));
    }

    /**
     * @param  array<string, mixed>|(Closure(array<string, mixed>): bool)|(Closure(AssertableEvent): mixed)|null  $matching  values by key (dot notation), a check of each event, or assertions typed AssertableEvent
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
     * @param  string|(Closure(AssertableReport): mixed)|null  $message  the message, or assertions on the report
     */
    public function assertReported(string $exception, string|Closure|null $message = null, ?int $times = null): void
    {
        $this->assertFound(count($this->reported($exception, $message)), $times, $this->describeReport($exception, $message), self::isAssertable($message) ? $this->closest($this->reported($exception, null), $message) : $this->listing($this->recorded(EventType::Exception)));
    }

    /**
     * @param  class-string  $exception  this class or one extending it
     * @param  string|(Closure(AssertableReport): mixed)|null  $message  the message, or assertions on the report
     */
    public function assertNotReported(string $exception, string|Closure|null $message = null): void
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
            $alert instanceof Closure => $this->passes($alert, $recorded),
            $alert !== null => $recorded->title === $alert,
            default => true,
        }));
    }

    /**
     * @param  string|(Closure(RecordedAlert): bool)|null  $alert  a title, or a check of each alert
     */
    public function assertAlerted(string|Closure|null $alert = null, ?string $topic = null, ?string $level = null, ?int $times = null): void
    {
        $this->assertFound(count($this->alerts($alert, $topic, $level)), $times, $this->describeAlert($alert, $topic, $level), self::isAssertable($alert) ? $this->closest($this->alerts(null, $topic, $level), $alert) : $this->alertListing($this->alerts()));
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
     * @param  array<string, mixed>|Closure|null  $matching
     */
    private function matches(array $event, array|Closure|null $matching): bool
    {
        if ($matching instanceof Closure) {
            return $this->passes($matching, $event);
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
     * @param  string|(Closure(AssertableReport): mixed)|null  $message
     * @return list<array<string, mixed>>
     */
    private function reported(string $exception, string|Closure|null $message): array
    {
        return $this->recorded(EventType::Exception, fn (array $event): bool => is_a($event['class'], $exception, true) && match (true) {
            $message === null => true,
            $message instanceof Closure => $this->passes($message, $event),
            default => $event['message'] === $message,
        });
    }

    /**
     * A closure that type-hints an assertable class makes assertions, whose outcome counts.
     * Any other is a check that answers for itself.
     */
    private function passes(Closure $check, mixed $subject): bool
    {
        $record = self::assertableFor($check, $subject);

        if ($record === null) {
            return $check($subject);
        }

        return $check($record) !== false && $record->passed();
    }

    private static function isAssertable(mixed $check): bool
    {
        return $check instanceof Closure && self::assertableClass($check) !== null;
    }

    /**
     * @return class-string<AssertableRecord>|null
     */
    private static function assertableClass(Closure $check): ?string
    {
        $parameters = (new ReflectionFunction($check))->getParameters();
        $type = ($parameters[0] ?? null)?->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : '';

        return in_array($name, [AssertableEvent::class, AssertableAlert::class, AssertableReport::class], true) ? $name : null;
    }

    private static function assertableFor(Closure $check, mixed $subject): ?AssertableRecord
    {
        $class = self::assertableClass($check);

        return $class === null ? null : new $class($subject);
    }

    /**
     * The record that came nearest, with what differed marked.
     *
     * @param  array<int, mixed>  $subjects
     */
    private function closest(array $subjects, mixed $check): string
    {
        if ($subjects === [] || ! $check instanceof Closure) {
            return "\nRecorded: nothing of that kind.";
        }

        $records = array_map(function (mixed $subject) use ($check): AssertableRecord {
            $class = self::assertableClass($check) ?? AssertableEvent::class;
            $record = new $class($subject);
            $check($record);

            return $record;
        }, $subjects);
        usort($records, fn (AssertableRecord $a, AssertableRecord $b): int => $a->mismatches() <=> $b->mismatches());

        return "\nClosest of ".count($records).":\n".$records[0]->describe();
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

    private function describeReport(string $exception, string|Closure|null $message): string
    {
        return "a reported [{$exception}]".match (true) {
            $message === null => '',
            $message instanceof Closure => ' passing the check',
            default => " with the message \"{$message}\"",
        };
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
            'event' => "activity: {$event['name']}",
            default => (string) $event['type'],
        }, $events));
    }

    /**
     * @param  string|Closure|null  $activity
     */
    private function describeActivity(string|Closure|null $activity, ?string $group): string
    {
        return trim('an activity'.(is_string($activity) ? " \"{$activity}\"" : '').($activity instanceof Closure ? ' matching the check' : '').($group !== null ? " in group {$group}" : ''));
    }

    /**
     * @param  list<RecordedActivity>  $activities
     */
    private function activityListing(array $activities): string
    {
        return $this->list('Recorded', array_map(fn (RecordedActivity $a): string => "\"{$a->name}\" (group: {$a->group})", $activities));
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
