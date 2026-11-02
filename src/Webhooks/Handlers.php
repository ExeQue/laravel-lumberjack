<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Webhooks;

use Closure;
use Illuminate\Contracts\Container\Container;

/**
 * What the app wants done when Lumberjack tells it something, registered in a
 * service provider:
 *
 *     Lumberjack::onException(PaymentFailed::class, [Status::New, Status::Escalating], fn (Alert $alert) => ...);
 *     Lumberjack::on(Status::Resolved, fn (Alert $alert) => ...);
 */
class Handlers
{
    /** @var list<array{classes: list<string>|null, statuses: list<Status>, handler: Closure}> */
    private array $handlers = [];

    public function __construct(
        private readonly Container $container,
    ) {
    }

    /**
     * @param  Status|list<Status>  $statuses
     */
    public function on(Status|array $statuses, callable $handler): static
    {
        return $this->add(null, $statuses, $handler);
    }

    /**
     * For issues of these exception classes (or their subclasses).
     *
     * @param  string|list<string>  $classes
     * @param  Status|list<Status>  $statuses
     */
    public function onException(string|array $classes, Status|array $statuses, callable $handler): static
    {
        return $this->add((array) $classes, $statuses, $handler);
    }

    public function handle(Alert $alert): int
    {
        $ran = 0;

        foreach ($this->handlers as $entry) {
            if (! in_array($alert->status, $entry['statuses'], true)) {
                continue;
            }

            if ($entry['classes'] !== null && ! array_filter($entry['classes'], fn (string $class): bool => $alert->isException($class))) {
                continue;
            }

            $this->container->call($entry['handler'], ['alert' => $alert]);
            $ran++;
        }

        return $ran;
    }

    /**
     * @param  list<string>|null  $classes
     * @param  Status|list<Status>  $statuses
     */
    private function add(?array $classes, Status|array $statuses, callable $handler): static
    {
        $this->handlers[] = [
            'classes' => $classes,
            'statuses' => is_array($statuses) ? $statuses : [$statuses],
            'handler' => Closure::fromCallable($handler),
        ];

        return $this;
    }
}
