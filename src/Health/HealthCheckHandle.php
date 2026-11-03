<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Throwable;

/**
 * One check by slug, fired from the app's own code: Lumberjack::health('disk:s3'). Its
 * state, as Lumberjack says it stands, can be read for any slug Lumberjack knows.
 * Each slug fires at most once per lumberjack.health.cooldown seconds, so a failure on
 * a busy path does not send a result on every request. The slug is looked up when the
 * handle is used, so it may be made before the check is registered.
 */
final class HealthCheckHandle
{
    public function __construct(
        private readonly HealthRegistry $registry,
        private readonly Container $container,
        private readonly string $slug,
    ) {
    }

    /**
     * Runs the check now and sends its result, e.g. right after a read from the disk
     * it watches failed. Null within the cooldown.
     *
     * @throws InvalidArgumentException when no check with the slug is registered
     */
    public function run(): ?CheckResult
    {
        $check = $this->registry->find($this->slug);

        if (! $this->mayFire()) {
            return null;
        }

        $runner = $this->container->make(HealthRunner::class);
        $result = $runner->run($check);
        $runner->record($check, $result);
        $runner->flush();

        return $result;
    }

    /**
     * Sends a result the app found itself, without running the check; it counts toward
     * degrading, going down and recovering like any other. False within the cooldown.
     *
     * @throws InvalidArgumentException when no check with the slug is registered
     */
    public function report(CheckResult $result): bool
    {
        $check = $this->registry->find($this->slug);

        if (! $this->mayFire()) {
            return false;
        }

        $runner = $this->container->make(HealthRunner::class);
        $runner->record($check, $result);
        $runner->flush();

        return true;
    }

    /**
     * @param  array<string, int|float>  $metrics
     */
    public function ok(?string $message = null, array $metrics = []): bool
    {
        return $this->report(CheckResult::ok($message, $metrics));
    }

    /**
     * @param  array<string, int|float>  $metrics
     */
    public function warning(?string $message = null, array $metrics = []): bool
    {
        return $this->report(CheckResult::warning($message, $metrics));
    }

    /**
     * @param  array<string, int|float>  $metrics
     */
    public function failed(?string $message = null, array $metrics = []): bool
    {
        return $this->report(CheckResult::failed($message, $metrics));
    }

    /**
     * Where Lumberjack says the check stands. Works for any slug Lumberjack knows,
     * registered here or not; Unknown for one it does not, or without an answer.
     */
    public function state(): HealthState
    {
        return $this->states()->get($this->slug);
    }

    /**
     * Ok only: recovering, stale, pending and unknown are not.
     */
    public function isHealthy(): bool
    {
        return $this->state()->isHealthy();
    }

    /**
     * When the check entered its state.
     */
    public function since(): ?CarbonImmutable
    {
        return $this->states()->snapshot($this->slug)?->since;
    }

    /**
     * The message of its latest result.
     */
    public function message(): ?string
    {
        return $this->states()->snapshot($this->slug)?->message;
    }

    private function states(): HealthStates
    {
        return $this->container->make(HealthStates::class);
    }

    /**
     * At most once per slug per cooldown across processes, through the cache. A cache
     * that is down itself (it may be what failed) does not stop the check.
     */
    private function mayFire(): bool
    {
        $cooldown = (int) config('lumberjack.health.cooldown', 30);

        if ($cooldown <= 0) {
            return true;
        }

        try {
            return $this->container->make(CacheFactory::class)->store()->add('lumberjack:health:fired:'.$this->slug, true, $cooldown);
        } catch (Throwable) {
            return true;
        }
    }
}
