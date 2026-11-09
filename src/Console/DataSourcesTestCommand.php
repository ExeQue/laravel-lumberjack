<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Console;

use Carbon\CarbonImmutable;
use ExeQue\Lumberjack\Contracts\DataSource;
use ExeQue\Lumberjack\DataSources\DataRange;
use ExeQue\Lumberjack\DataSources\DataSourceEndpoint;
use ExeQue\Lumberjack\DataSources\DataSourceRegistry;
use ExeQue\Lumberjack\DataSources\DataSourceResolver;
use ExeQue\Lumberjack\DataSources\Http\DataSourcesController;
use ExeQue\Lumberjack\DataSources\PullSignature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * Checks the setup the way Lumberjack will meet it. The endpoint answers 404 to everything
 * it does not trust, so this says which part it is.
 */
class DataSourcesTestCommand extends Command
{
    protected $name = 'lumberjack:data-sources:test';

    protected $description = 'Check the data sources and that Lumberjack can reach them';

    public function handle(DataSourceRegistry $registry, DataSourceResolver $resolver, Http $http): int
    {
        $token = config('lumberjack.data_sources.pull_token');
        $url = DataSourceEndpoint::url();
        $failed = false;

        if (! $this->option('skip-http')) {
            if (! (bool) config('lumberjack.enabled', true) || ! (bool) config('lumberjack.data_sources.enabled', false)) {
                $this->components->error('The endpoint is off: it needs LUMBERJACK_ENABLED and LUMBERJACK_DATA_SOURCES_ENABLED on.');

                return self::FAILURE;
            }

            if (! is_string($token) || $token === '') {
                $this->components->error('LUMBERJACK_PULL_TOKEN is not set: the endpoint answers nobody. Connect the app on the Data sources page in Lumberjack.');

                return self::FAILURE;
            }

            if (! str_starts_with($url, 'https://')) {
                $this->components->error("{$url} is not https. Lumberjack only pulls over https: fix APP_URL.");

                return self::FAILURE;
            }

            if (! $this->cacheRemembers()) {
                $this->components->error('The cache cannot remember a pull, so every pull is refused as a replay: set CACHE_STORE to a shared store, not null.');

                return self::FAILURE;
            }
        }

        if (! $this->option('skip-http') && config('cache.stores.'.config('cache.default').'.driver') === 'array') {
            $this->components->warn('CACHE_STORE is array: each request has its own, so replays are not seen. Use a shared store.');
        }

        $sources = $registry->all();

        if (! $registry->isComplete()) {
            $this->components->error('A data source was refused or could not be made: see the log.');
            $failed = true;
        }

        if ($sources === []) {
            $this->components->warn('No data sources are registered.');

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $to = CarbonImmutable::now('UTC');
        $range = new DataRange($to->subDay(), $to);

        foreach ($resolver->resolve(array_keys($sources), $range) as $key => $answer) {
            $failed = $failed || isset($answer['error']);
            $this->components->twoColumnDetail($key, $answer['error'] ?? 'ok, '.$answer['shape']);

            if (isset($answer['error'])) {
                $this->explain($sources[$key], $range);
            }
        }

        if ($this->option('skip-http')) {
            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $query = ['from' => $range->from->getTimestamp(), 'only' => (string) array_key_first($sources), 'to' => $range->to->getTimestamp()];

        try {
            $response = $http->withHeaders(PullSignature::headers($token, $url, $query))->timeout(15)->withoutRedirecting()->get($url, $query);
        } catch (Throwable $e) {
            $this->components->error("Could not reach {$url}: ".$e->getMessage());

            return self::FAILURE;
        }

        if ($response->status() !== 200) {
            $this->components->error("{$url} answered {$response->status()}. A 404 here usually means a proxy that hides https or the host from Laravel: check TrustProxies, or the path.");

            return self::FAILURE;
        }

        if ($response->json('version') !== DataSourcesController::FORMAT_VERSION || ! is_array($response->json('sources')[$query['only']] ?? null)) {
            $this->components->error("{$url} answered 200, but not as the endpoint does: something else answers at that address.");

            return self::FAILURE;
        }

        $this->components->info("{$url} answers a signed request.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** The reason itself, which the endpoint keeps to the exception handler. */
    private function explain(DataSource $source, DataRange $range): void
    {
        try {
            $source->resolve($range);
        } catch (Throwable $e) {
            $this->components->bulletList([$e::class.': '.$e->getMessage()]);
        }
    }

    private function cacheRemembers(): bool
    {
        try {
            return Cache::store()->add('lumberjack:pull-probe:'.Str::random(16), true, 5);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<InputOption>
     */
    protected function getOptions(): array
    {
        return [
            new InputOption('skip-http', null, InputOption::VALUE_NONE, 'Only resolve the sources, without calling the endpoint'),
        ];
    }
}
