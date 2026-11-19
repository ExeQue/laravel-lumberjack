<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Ai\Prism;

use Closure;
use Composer\InstalledVersions;
use ExeQue\Lumberjack\Ai\AiCall;
use ExeQue\Lumberjack\Ai\AiCallRecorder;
use Illuminate\Contracts\Foundation\Application;
use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider;
use SensitiveParameter;
use Throwable;

/**
 * Wraps the Prism manager so every provider it resolves records its calls. The key is
 * fingerprinted here, from the config the provider is made with (a key given with
 * usingProviderConfig() included), and only the fingerprint is kept.
 *
 * @internal
 */
final class RecordingPrismManager extends PrismManager
{
    public function __construct(
        private readonly PrismManager $inner,
        private readonly AiCallRecorder $ai,
        Application $app,
    ) {
        parent::__construct($app);
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     */
    public function resolve(ProviderEnum|string $name, #[SensitiveParameter] array $providerConfig = []): Provider
    {
        $provider = $this->inner->resolve($name, $providerConfig);

        // Already recorded further down a chain of decorators that cannot say what they wrap.
        if ($provider instanceof RecordingProvider) {
            return $provider;
        }

        // A built-in provider is its vendor (as Laravel AI's driver); one added with extend() its own name.
        $name = strtolower($name instanceof ProviderEnum ? $name->value : $name);
        $driver = $name;
        $key = array_merge((array) config("prism.providers.{$name}", []), $providerConfig)['api_key'] ?? null;

        return new RecordingProvider($provider, $this->ai, $driver, AiCall::fingerprint($driver, is_string($key) ? $key : null));
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

    /** The Prism versions the decorator forwards every Provider method of. */
    public const string SUPPORTED_FROM = '0.100';

    public const string SUPPORTED_BEFORE = '0.104';

    /**
     * Tells the installed Prism version; tests swap it.
     *
     * @var (Closure(): ?string)|null
     */
    public static ?Closure $versionResolver = null;

    /** The installed Prism version, or null when it cannot be told. */
    public static function installedVersion(): ?string
    {
        if (self::$versionResolver !== null) {
            return (self::$versionResolver)();
        }

        try {
            return class_exists(InstalledVersions::class) ? InstalledVersions::getVersion('prism-php/prism') : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Whether this version is one the decorator was tested with; an unknown one is not. */
    public static function supports(?string $version): bool
    {
        if ($version === null || preg_match('/^v?(\d+\.\d+(\.\d+)*)/', $version, $match) !== 1) {
            return false;
        }

        return version_compare($match[1], self::SUPPORTED_FROM, '>=') && version_compare($match[1], self::SUPPORTED_BEFORE, '<');
    }

    /** Whether a manager is this one, or wraps it somewhere down the chain of decorators. */
    public static function wraps(PrismManager $manager): bool
    {
        for ($depth = 0; $depth < 10; $depth++) {
            if ($manager instanceof self) {
                return true;
            }

            $inner = method_exists($manager, 'inner') ? $manager->inner() : null;

            if (! $inner instanceof PrismManager) {
                return false;
            }

            $manager = $inner;
        }

        return false;
    }
}
