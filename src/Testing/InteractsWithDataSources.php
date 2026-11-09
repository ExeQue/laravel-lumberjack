<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing;

use ExeQue\Lumberjack\Contracts\DataSource;
use ExeQue\Lumberjack\DataSources\DataRange;
use ExeQue\Lumberjack\DataSources\DataSourceEndpoint;
use ExeQue\Lumberjack\DataSources\DataSourceRegistry;
use ExeQue\Lumberjack\DataSources\Http\VerifyPullSignature;
use ExeQue\Lumberjack\DataSources\PullSignature;
use ExeQue\Lumberjack\DataSources\Shape;
use ExeQue\Lumberjack\Exceptions\InvalidDataSourceException;
use ExeQue\Lumberjack\Testing\DataSources\TestedDataSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * For a Laravel test case: $this->dataSource('orders:total')->assertNumber(3).
 */
trait InteractsWithDataSources
{
    /**
     * @param  DataSource|string  $source  a registered key, a class, or an instance
     */
    protected function dataSource(DataSource|string $source): TestedDataSource
    {
        $registry = $this->app->make(DataSourceRegistry::class);

        return new TestedDataSource($this->findDataSource($source, $registry), $registry, $this->pullDataSource(...));
    }

    private function findDataSource(DataSource|string $source, DataSourceRegistry $registry): DataSource
    {
        if (is_string($source) && ! is_subclass_of($source, DataSource::class)) {
            return $registry->all()[$source] ?? Assert::fail('No data source ['.$source.']. Registered: '.(implode(', ', array_keys($registry->all())) ?: 'none').'.');
        }

        $source = is_string($source) ? $this->app->make($source) : $source;

        try {
            Shape::of($source);
            Assert::assertMatchesRegularExpression(DataSourceRegistry::KEY_PATTERN, $source->key(), 'Lumberjack refuses this key.');
        } catch (InvalidDataSourceException $e) {
            Assert::fail($e->getMessage());
        }

        return $source;
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function pullDataSource(string $key, DataRange $range): TestResponse
    {
        if (! Route::has('lumberjack.data-sources')) {
            Assert::fail('The data sources endpoint is not there: your tests need LUMBERJACK_ENABLED=true and LUMBERJACK_DATA_SOURCES_ENABLED=true.');
        }

        $token = config('lumberjack.data_sources.pull_token');

        if (! is_string($token) || $token === '') {
            config(['lumberjack.data_sources.pull_token' => $token = 'lj_pull_testing']);
        }

        $query = ['from' => $range->from->getTimestamp(), 'only' => $key, 'to' => $range->to->getTimestamp()];

        if ($range->bucket !== 3600 || $range->timezone !== 'UTC') {
            $query += ['bucket' => $range->bucket, 'timezone' => $range->timezone];
        }
        $url = DataSourceEndpoint::url();
        $headers = PullSignature::headers($token, $url, $query);

        // A signature is single-use, and two pulls in the same second would share one.
        Cache::forget(VerifyPullSignature::seenKey($headers[PullSignature::HEADER_SIGNATURE]));

        return $this->withHeaders($headers)->get('https://'.(parse_url($url, PHP_URL_HOST) ?: 'localhost').route('lumberjack.data-sources', [], false).'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }
}
