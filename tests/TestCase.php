<?php

declare(strict_types=1);

namespace Tests;

use ExeQue\Lumberjack\AgentClient;
use ExeQue\Lumberjack\LumberjackServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Tests\Support\FakeAgentClient;

abstract class TestCase extends Orchestra
{
    protected FakeAgentClient $agent;

    /** @var array<string, mixed> config to boot the next application with */
    private array $config = [];

    protected function getPackageProviders($app): array
    {
        return [LumberjackServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->agent = new FakeAgentClient();
        $app->instance(AgentClient::class, $this->agent);

        $app['config']->set('lumberjack.url', 'https://lumberjack.test');
        $app['config']->set('lumberjack.token', 'secret-token');
        $app['config']->set('lumberjack.public_key', 'pk_123');
        $app['config']->set('lumberjack.environment', 'testing');
        $app['config']->set('lumberjack.release', 'abc123');
        $app['config']->set('database.default', 'testing');

        foreach ($this->config as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    /**
     * Boots the application again with this config, as an app would start with it.
     *
     * @param  array<string, mixed>  $config
     */
    protected function withConfig(array $config): static
    {
        $this->config = $config;
        $this->refreshApplication();

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function sentEvents(?string $type = null): array
    {
        $events = array_merge(...array_map(fn (array $payload): array => $payload['events'], $this->agent->payloads)) ?: [];

        return array_values(array_filter($events, fn (array $event): bool => $type === null || $event['type'] === $type));
    }
}
