<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

use Illuminate\Contracts\Container\Container;

/**
 * Makes an object from a config entry: an instance as it is, a class, or
 * [class, 'parameter' => value, ...] made by the container with those named parameters.
 */
final class ContainerEntry
{
    /**
     * @param  array<string, mixed>  $defaults  named parameters the entry's own win over
     */
    public static function make(Container $container, mixed $entry, array $defaults = []): ?object
    {
        if (is_object($entry)) {
            return $entry;
        }

        $parameters = (array) $entry;
        $class = $parameters[0] ?? null;
        unset($parameters[0]);

        if (! is_string($class) || $class === '') {
            return null;
        }

        $object = $container->make($class, [...$defaults, ...$parameters]);

        return is_object($object) ? $object : null;
    }
}
