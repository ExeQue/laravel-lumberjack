<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health;

use Closure;
use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\Checks\CacheHealthCheck;
use ExeQue\Lumberjack\Health\Checks\DatabaseHealthCheck;
use ExeQue\Lumberjack\Health\Checks\DebugModeHealthCheck;
use ExeQue\Lumberjack\Health\Checks\DiskHealthCheck;
use ExeQue\Lumberjack\Health\Checks\FailedJobsHealthCheck;
use ExeQue\Lumberjack\Health\Checks\FreshFileHealthCheck;
use ExeQue\Lumberjack\Health\Checks\HorizonHealthCheck;
use ExeQue\Lumberjack\Health\Checks\HttpHealthCheck;
use ExeQue\Lumberjack\Health\Checks\MailHealthCheck;
use ExeQue\Lumberjack\Health\Checks\MigrationsHealthCheck;
use ExeQue\Lumberjack\Health\Checks\QueueHealthCheck;
use ExeQue\Lumberjack\Health\Checks\RedisHealthCheck;
use ExeQue\Lumberjack\Support\ContainerEntry;
use ExeQue\Lumberjack\Support\Safely;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Throwable;

/**
 * The checks from config and from Lumberjack::healthChecks(). Entries are kept as given and
 * only made when lumberjack:health runs, so registering them costs a request nothing.
 * Every built-in has a method here taking the same parameters as its constructor.
 */
final class HealthRegistry
{
    /** @var list<mixed> */
    private array $entries = [];

    /**
     * @param  array<mixed>  $entries  each a class, [class, 'parameter' => value, ...] or a HealthCheck
     */
    public function __construct(
        private readonly Container $container,
        array $entries = [],
    ) {
        // From config: a bad entry is reported and skipped, never thrown into the app's boot
        // or scheduler. Lumberjack::healthChecks()->add() still throws, at the line that is wrong.
        foreach ($entries as $entry) {
            try {
                $this->add($entry);
            } catch (Throwable $e) {
                Safely::problem('Lumberjack skipped a health check in lumberjack.health.checks: '.$e->getMessage());
            }
        }
    }

    /**
     * Each argument is one check: a HealthCheck, a class, or [class, 'parameter' => value, ...].
     * Spread a list: ->add(...$checks).
     *
     * @param  HealthCheck|string|array<mixed>  ...$checks
     */
    public function add(HealthCheck|string|array ...$checks): self
    {
        foreach ($checks as $entry) {
            // Slugs that are known now fail at registration, not when the checks run.
            if ($entry instanceof HealthCheck) {
                $entry->slug();
            } elseif (is_array($entry) && is_string($entry['slug'] ?? null)) {
                Slug::validate($entry['slug']);
            }

            $this->entries[] = $entry;
        }

        return $this;
    }

    /**
     * A closure check: true is ok, false failed, a CheckResult as it is; an exception is failed.
     */
    public function check(string $slug, Closure $callback, int $degradeAfter = 2, int $recoverAfter = 5, int $downAfter = 5, int $timeout = 10): self
    {
        return $this->add(new ClosureHealthCheck($slug, $callback, $degradeAfter, $recoverAfter, $downAfter, $timeout));
    }

    public function disk(string $disk, int $degradeAfter = 2, int $recoverAfter = 5, int $downAfter = 5, ?int $warnAt = null, ?int $failAt = null, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([DiskHealthCheck::class, ...get_defined_vars()]);
    }

    public function database(?string $connection = null, int $degradeAfter = 2, int $recoverAfter = 5, int $downAfter = 5, ?int $maxLatencyMs = null, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([DatabaseHealthCheck::class, ...get_defined_vars()]);
    }

    public function cache(?string $store = null, int $degradeAfter = 2, int $recoverAfter = 5, int $downAfter = 5, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([CacheHealthCheck::class, ...get_defined_vars()]);
    }

    public function redis(?string $connection = null, int $degradeAfter = 2, int $recoverAfter = 5, int $downAfter = 5, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([RedisHealthCheck::class, ...get_defined_vars()]);
    }

    public function queue(?string $connection = null, int $degradeAfter = 2, int $recoverAfter = 5, int $downAfter = 5, ?string $queue = null, ?int $warnAt = null, ?int $failAt = null, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([QueueHealthCheck::class, ...get_defined_vars()]);
    }

    public function horizon(int $degradeAfter = 2, int $recoverAfter = 5, int $downAfter = 5, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([HorizonHealthCheck::class, ...get_defined_vars()]);
    }

    public function http(string $url, int $degradeAfter = 2, int $recoverAfter = 5, int $downAfter = 5, int $status = 200, ?int $maxLatencyMs = null, string $method = 'GET', int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([HttpHealthCheck::class, ...get_defined_vars()]);
    }

    /**
     * @param  list<string>  $environments
     */
    public function debugMode(int $degradeAfter = 2, int $recoverAfter = 5, int $downAfter = 5, array $environments = ['production'], int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([DebugModeHealthCheck::class, ...get_defined_vars()]);
    }

    public function migrations(?string $connection = null, int $degradeAfter = 2, int $recoverAfter = 5, int $downAfter = 5, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([MigrationsHealthCheck::class, ...get_defined_vars()]);
    }

    public function failedJobs(int $degradeAfter = 2, int $recoverAfter = 5, int $downAfter = 5, int $withinMinutes = 60, int $warnAt = 1, ?int $failAt = null, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([FailedJobsHealthCheck::class, ...get_defined_vars()]);
    }

    public function freshFile(string $disk, string $path, int $maxAgeMinutes, int $degradeAfter = 2, int $recoverAfter = 5, int $downAfter = 5, bool $recursive = false, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([FreshFileHealthCheck::class, ...get_defined_vars()]);
    }

    public function mail(?string $mailer = null, int $degradeAfter = 2, int $recoverAfter = 5, int $downAfter = 5, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([MailHealthCheck::class, ...get_defined_vars()]);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Every check by slug; a later check with the same slug replaces an earlier one.
     *
     * @return array<string, HealthCheck>
     */
    public function checks(): array
    {
        $checks = [];

        foreach ($this->entries as $entry) {
            // One check that cannot be made (a class that is gone, a bad parameter) is
            // reported and skipped; the others still run.
            try {
                $check = ContainerEntry::make($this->container, $entry);

                if (! $check instanceof HealthCheck) {
                    throw new InvalidArgumentException(sprintf('Health check [%s] does not implement %s.', is_object($entry) ? $entry::class : json_encode($entry), HealthCheck::class));
                }

                $checks[$check->slug()] = $check;
            } catch (Throwable $e) {
                Safely::problem('Lumberjack skipped a health check: '.$e->getMessage());
            }
        }

        return $checks;
    }

    /**
     * The check with this slug, for {@see HealthCheckHandle}.
     *
     * @internal
     */
    public function find(string $slug): HealthCheck
    {
        return $this->checks()[$slug] ?? throw new InvalidArgumentException("No health check [{$slug}] is registered.");
    }
}
