<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Listeners;

use ExeQue\Lumberjack\Activity\Payload;
use ExeQue\Lumberjack\Attributes\RecordsLumberjackActivity;
use ExeQue\Lumberjack\Contracts\TracksLumberjackActivity;
use ExeQue\Lumberjack\PendingActivity;
use ExeQue\Lumberjack\Recorder;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/**
 * Records a dispatched event as an activity: by its toLumberjackActivity() when it
 * implements TracksLumberjackActivity, else by its #[RecordsLumberjackActivity].
 */
final class RecordsActivity
{
    /**
     * Each event class's attribute, or false without one. Keyed by class, which the code
     * bounds, so it cannot grow past the app's own event classes.
     *
     * @var array<class-string, RecordsLumberjackActivity|false>
     */
    private array $attributes = [];

    public function __construct(private readonly Recorder $recorder)
    {
    }

    /**
     * For a wildcard listener: the event name and the dispatched arguments.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function handle(string $name, array $payload): void
    {
        $event = $payload[0] ?? null;

        if (! is_object($event)) {
            return;
        }

        $this->activity($event)?->send();
    }

    /**
     * The activity an event stands for, if it stands for one.
     */
    public function activity(object $event): ?PendingActivity
    {
        if ($event instanceof TracksLumberjackActivity) {
            return $event->toLumberjackActivity();
        }

        $attribute = $this->attributes[$event::class] ??= ((new ReflectionClass($event))->getAttributes(RecordsLumberjackActivity::class)[0] ?? null)?->newInstance() ?? false;

        return $attribute === false ? null : $this->fromAttribute($attribute, $event);
    }

    private function fromAttribute(RecordsLumberjackActivity $attribute, object $event): PendingActivity
    {
        $activity = (new PendingActivity($this->recorder, $attribute->name))
            ->by(...self::reference($attribute->actor, $event));

        foreach ((array) $attribute->subject as $subject) {
            $activity->on(...self::reference($subject, $event));
        }

        if ($attribute->group !== null) {
            $activity->group($attribute->group);
        }

        if ($attribute->byUser && ! array_key_exists('actor', $activity->toArray())) {
            $activity->byUser();
        }

        if ($attribute->payload !== []) {
            $activity->with(Payload::resolve($attribute->payload, $event));
        }

        return $activity;
    }

    /**
     * An actor or subject as the attribute gives it: "@path" to a model, or "type:id"
     * whose id may be "@path" too ("order:@order.uuid").
     *
     * @return array{0: Model|string|null, 1: string|int|null}
     */
    private static function reference(?string $value, object $event): array
    {
        if ($value === null) {
            return [null, null];
        }

        if (str_starts_with($value, '@') && ! str_starts_with($value, '@@')) {
            $model = Payload::read($value, $event);

            return $model instanceof Model ? [$model, null] : [null, null];
        }

        if (! str_contains($value, ':')) {
            return [null, null];
        }

        [$type, $id] = explode(':', $value, 2);
        $id = Payload::read($id, $event);

        return is_string($id) || is_int($id) ? [$type, $id] : [null, null];
    }
}
