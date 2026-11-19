<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ai;

use Illuminate\Contracts\Foundation\Application;
use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider;

/**
 * Another package's decorator of the Prism manager that cannot say what it wraps.
 */
class OpaquePrismManager extends PrismManager
{
    public function __construct(private readonly PrismManager $wrapped, Application $app)
    {
        parent::__construct($app);
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     */
    public function resolve(ProviderEnum|string $name, array $providerConfig = []): Provider
    {
        return $this->wrapped->resolve($name, $providerConfig);
    }
}
