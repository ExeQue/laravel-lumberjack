<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Release;

use ExeQue\Lumberjack\Contracts\ReleaseResolver;
use ExeQue\Lumberjack\Support\ContainerEntry;
use Illuminate\Contracts\Container\Container;

/**
 * Tries resolvers one by one and gives the first release one of them finds. Each
 * entry is written like release_resolver itself: a class, or [class, 'parameter' =>
 * value, ...], made by the container with the app's root as path.
 */
final class StackReleaseResolver implements ReleaseResolver
{
    /**
     * @param  list<mixed>  $resolvers
     */
    public function __construct(
        private readonly Container $container,
        private readonly string $path,
        private readonly array $resolvers = [GitReleaseResolver::class, ComposerReleaseResolver::class],
    ) {
    }

    public function resolve(): ?string
    {
        foreach ($this->resolvers as $entry) {
            $release = self::make($this->container, $entry, $this->path)?->resolve();

            if ($release !== null && $release !== '') {
                return $release;
            }
        }

        return null;
    }

    /**
     * One resolver from its config entry: a class, [class, 'parameter' => value, ...],
     * or a list of entries to try in order. Null for an empty entry or a class that is
     * no resolver.
     */
    public static function make(Container $container, mixed $entry, string $path): ?ReleaseResolver
    {
        // A plain list is a stack of its entries; [class, 'name' => value] has names.
        if (is_array($entry) && array_is_list($entry)) {
            return new self($container, $path, $entry);
        }

        $resolver = ContainerEntry::make($container, $entry, ['path' => $path]);

        return $resolver instanceof ReleaseResolver ? $resolver : null;
    }
}
