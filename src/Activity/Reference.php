<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Activity;

use Illuminate\Database\Eloquent\Model;

/**
 * An actor or subject as Lumberjack keeps it: a type and a key, never more.
 */
final readonly class Reference
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
    }

    /**
     * A model becomes its morph alias (or class) and key; anything else is a type with
     * the given key. Null when there is no key to refer to.
     */
    public static function of(Model|string|null $type, string|int|null $id = null): ?self
    {
        if ($type instanceof Model) {
            $key = $type->getKey();

            return is_scalar($key) && (string) $key !== '' ? new self($type->getMorphClass(), (string) $key) : null;
        }

        return $type !== null && $type !== '' && $id !== null && (string) $id !== '' ? new self($type, (string) $id) : null;
    }

    /**
     * @return array{type: string, id: string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }
}
