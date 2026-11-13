<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack;

use ExeQue\Lumberjack\Activity\Actor;
use ExeQue\Lumberjack\Activity\Payload;
use ExeQue\Lumberjack\Activity\Reference;
use ExeQue\Lumberjack\Contracts\ActsAsLumberjackActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Conditionable;

/**
 * An activity being put together: something that happened in the app, by its name. It
 * goes out with the rest of the request or job once send() is called. Nobody is set as
 * its actor unless asked, and an actor or subject is only ever a type and a key.
 */
class PendingActivity
{
    use Conditionable;

    /** @var array<string, mixed> */
    private array $event;

    public function __construct(private readonly Recorder $recorder, string $name)
    {
        $this->event = ['type' => 'activity', 'name' => $name];
    }

    /** The group it is counted in. Without one it is "default". */
    public function group(string $group): static
    {
        $this->event['group'] = $group;

        return $this;
    }

    /**
     * Who did it: an object acting as an actor (ActsAsLumberjackActor), an Actor, a model,
     * or a type and its key ("system", "scheduler").
     */
    public function by(ActsAsLumberjackActor|Actor|Model|string|null $actor, string|int|null $id = null): static
    {
        return $this->reference('actor', Reference::of($actor, $id));
    }

    /**
     * The logged in user did it. Without one, it has no actor.
     */
    public function byUser(?string $guard = null): static
    {
        $user = auth()->guard($guard)->user();

        return $user instanceof ActsAsLumberjackActor || $user instanceof Model ? $this->by($user) : $this;
    }

    /**
     * What it was done to: a model, or a type and its key; an object acting as an actor is
     * known the same way here (a user invited to a team). Call it again for each further
     * subject, such as an image and the gallery it was uploaded to. Lumberjack keeps ten.
     */
    public function on(ActsAsLumberjackActor|Model|string|null $subject, string|int|null $id = null): static
    {
        $reference = Reference::of($subject, $id)?->toArray();

        if ($reference !== null && ! in_array($reference, $this->event['subjects'] ?? [], true)) {
            $this->event['subjects'][] = $reference;
        }

        return $this;
    }

    /**
     * What else to keep with it, merged into what is there. A model in it is kept as its
     * type and key only.
     *
     * @param  array<string, mixed>  $payload
     */
    public function with(array $payload): static
    {
        $this->event['payload'] = [...$this->event['payload'] ?? [], ...Payload::plain($payload)];

        return $this;
    }

    /** Records it with the rest of the request or job. */
    public function send(): void
    {
        $this->recorder->record($this->event);
    }

    /**
     * The event as it is sent.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->event;
    }

    private function reference(string $key, ?Reference $reference): static
    {
        if ($reference === null) {
            unset($this->event[$key]);
        } else {
            $this->event[$key] = $reference->toArray();
        }

        return $this;
    }
}
