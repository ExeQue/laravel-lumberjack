<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ai;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider;

/**
 * Another package's decorator of the Prism manager, such as a debugger's.
 */
class CountingPrismManager extends PrismManager
{
    public int $resolved = 0;

    public function __construct(private readonly PrismManager $inner, Application $app)
    {
        parent::__construct($app);
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     */
    public function resolve(ProviderEnum|string $name, array $providerConfig = []): Provider
    {
        $this->resolved++;

        return $this->inner->resolve($name, $providerConfig);
    }

    public function extend(string $provider, Closure $callback): self
    {
        $this->inner->extend($provider, $callback);

        return $this;
    }

    public function inner(): PrismManager
    {
        return $this->inner;
    }
}
