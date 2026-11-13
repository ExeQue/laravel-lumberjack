<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Attributes;

use Attribute;

/**
 * Records the event class it is on as an activity when it is dispatched. A payload value
 * starting with "@" is read from the event, such as "@order.uuid" (data_get); "@@" is a
 * literal "@". The actor and each subject are "@path" to a model, or "type:id" whose id
 * may be "@path" ("order:@order.uuid"); several subjects are a list. Name and group are
 * always as written.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RecordsLumberjackActivity
{
    /**
     * @param  string|list<string>|null  $subject  one subject, or a list of them
     * @param  array<array-key, mixed>  $payload
     * @param  bool  $byUser  the logged in user is the actor when no actor is given
     */
    public function __construct(
        public string $name,
        public ?string $group = null,
        public ?string $actor = null,
        public string|array|null $subject = null,
        public array $payload = [],
        public bool $byUser = false,
    ) {
    }
}
