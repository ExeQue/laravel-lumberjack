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
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Throwable;

/**
 * The checks from config and from Lumberjack::health(). Entries are kept as given and
 * only made when lumberjack:health runs, so registering them costs a request nothing.
 * Every built-in has a method here taking the same parameters as its constructor.
 */
final class HealthRegistry
{
    /** A check fired from the app runs at most this often. */
    public const int FIRE_EVERY_SECONDS = 30;

    /** @var list<mixed> */
    private array $entries = [];

    /**
     * @param  array<mixed>  $entries  each a class, [class, 'parameter' => value, ...] or a HealthCheck
     */
    public function __construct(
        private readonly Container $container,
        array $entries = [],
    ) {
        $this->add($entries);
    }

    /**
     * One check (a HealthCheck, a class or [class, 'parameter' => value, ...]) or a list of them.
     *
     * @param  HealthCheck|string|array<mixed>  $checks
     */
    public function add(HealthCheck|string|array $checks): self
    {
        foreach (is_array($checks) && array_is_list($checks) ? $checks : [$checks] as $entry) {
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
    public function check(string $slug, Closure $callback, int $degradeAfter = 2, int $recoverAfter = 5, int $timeout = 10): self
    {
        return $this->add(new ClosureHealthCheck($slug, $callback, $degradeAfter, $recoverAfter, $timeout));
    }

    public function disk(string $disk, int $degradeAfter = 2, int $recoverAfter = 5, ?int $warnAt = null, ?int $failAt = null, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([DiskHealthCheck::class, ...get_defined_vars()]);
    }

    public function database(?string $connection = null, int $degradeAfter = 2, int $recoverAfter = 5, ?int $maxLatencyMs = null, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([DatabaseHealthCheck::class, ...get_defined_vars()]);
    }

    public function cache(?string $store = null, int $degradeAfter = 2, int $recoverAfter = 5, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([CacheHealthCheck::class, ...get_defined_vars()]);
    }

    public function redis(?string $connection = null, int $degradeAfter = 2, int $recoverAfter = 5, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([RedisHealthCheck::class, ...get_defined_vars()]);
    }

    public function queue(?string $connection = null, int $degradeAfter = 2, int $recoverAfter = 5, ?string $queue = null, ?int $warnAt = null, ?int $failAt = null, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([QueueHealthCheck::class, ...get_defined_vars()]);
    }

    public function horizon(int $degradeAfter = 2, int $recoverAfter = 5, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([HorizonHealthCheck::class, ...get_defined_vars()]);
    }

    public function http(string $url, int $degradeAfter = 2, int $recoverAfter = 5, int $status = 200, ?int $maxLatencyMs = null, string $method = 'GET', int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([HttpHealthCheck::class, ...get_defined_vars()]);
    }

    /**
     * @param  list<string>  $environments
     */
    public function debugMode(int $degradeAfter = 2, int $recoverAfter = 5, array $environments = ['production'], int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([DebugModeHealthCheck::class, ...get_defined_vars()]);
    }

    public function migrations(?string $connection = null, int $degradeAfter = 2, int $recoverAfter = 5, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([MigrationsHealthCheck::class, ...get_defined_vars()]);
    }

    public function failedJobs(int $degradeAfter = 2, int $recoverAfter = 5, int $withinMinutes = 60, int $warnAt = 1, ?int $failAt = null, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([FailedJobsHealthCheck::class, ...get_defined_vars()]);
    }

    public function freshFile(string $disk, string $path, int $maxAgeMinutes, int $degradeAfter = 2, int $recoverAfter = 5, bool $recursive = false, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([FreshFileHealthCheck::class, ...get_defined_vars()]);
    }

    public function mail(?string $mailer = null, int $degradeAfter = 2, int $recoverAfter = 5, int $timeout = 10, ?string $slug = null): self
    {
        return $this->add([MailHealthCheck::class, ...get_defined_vars()]);
    }

    /**
     * Runs a registered check now and sends its result, e.g. right after a read from
     * the disk it watches failed. Null when the check ran within the last
     * FIRE_EVERY_SECONDS, so a failure on a busy path does not run it on every request.
     */
    public function run(string $slug): ?CheckResult
    {
        $check = $this->find($slug);

        if (! $this->mayFire($slug)) {
            return null;
        }

        $runner = $this->container->make(HealthRunner::class);
        $result = $runner->run($check);
        $runner->record($check, $result);
        $runner->flush();

        return $result;
    }

    /**
     * Sends a result the app found itself for a registered check, without running it;
     * it counts toward degrading and recovering like any other. False when throttled.
     */
    public function report(string $slug, CheckResult $result): bool
    {
        $check = $this->find($slug);

        if (! $this->mayFire($slug)) {
            return false;
        }

        $runner = $this->container->make(HealthRunner::class);
        $runner->record($check, $result);
        $runner->flush();

        return true;
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
            $check = ContainerEntry::make($this->container, $entry);

            if (! $check instanceof HealthCheck) {
                throw new InvalidArgumentException(sprintf('Health check [%s] does not implement %s.', is_object($entry) ? $entry::class : json_encode($entry), HealthCheck::class));
            }

            $checks[$check->slug()] = $check;
        }

        return $checks;
    }

    private function find(string $slug): HealthCheck
    {
        return $this->checks()[$slug] ?? throw new InvalidArgumentException("No health check [{$slug}] is registered.");
    }

    /**
     * At most once per slug per FIRE_EVERY_SECONDS across processes, through the cache.
     * A cache that is down itself (it may be what failed) does not stop the check.
     */
    private function mayFire(string $slug): bool
    {
        try {
            return $this->container->make(CacheFactory::class)->store()->add('lumberjack:health:fired:'.$slug, true, self::FIRE_EVERY_SECONDS);
        } catch (Throwable) {
            return true;
        }
    }
}
