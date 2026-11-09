<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ExeQue\Lumberjack\Contracts\DataSource;
use ExeQue\Lumberjack\Contracts\DataSourceResult;
use ExeQue\Lumberjack\DataSources\CacheFor;
use ExeQue\Lumberjack\DataSources\DataRange;
use ExeQue\Lumberjack\DataSources\DataSourceEndpoint;
use ExeQue\Lumberjack\DataSources\DataSourceRegistry;
use ExeQue\Lumberjack\DataSources\DataSourceResolver;
use ExeQue\Lumberjack\DataSources\PullSignature;
use ExeQue\Lumberjack\DataSources\Results\ListResult;
use ExeQue\Lumberjack\DataSources\Results\ListRow;
use ExeQue\Lumberjack\DataSources\Results\NumberResult;
use ExeQue\Lumberjack\DataSources\Results\SeriesPoint;
use ExeQue\Lumberjack\DataSources\Results\SeriesResult;
use ExeQue\Lumberjack\DataSources\Results\TableResult;
use ExeQue\Lumberjack\DataSources\Shape;
use ExeQue\Lumberjack\Exceptions\InvalidDataSourceException;
use ExeQue\Lumberjack\Exceptions\InvalidDataSourceResultException;
use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\Testing\EventType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CountingDependency
{
    public int $calls = 0;
}

class ContainerMadeSource implements DataSource
{
    public function __construct(public CountingDependency $dependency)
    {
    }

    public function key(): string
    {
        return 'made';
    }

    public function label(): string
    {
        return 'Made by the container';
    }

    public function resolve(DataRange $range): NumberResult
    {
        return new NumberResult(++$this->dependency->calls);
    }
}

#[CacheFor(seconds: 300)]
class CachedSource extends ContainerMadeSource
{
    public function key(): string
    {
        return 'cached';
    }
}

class RowsSource implements DataSource
{
    public function key(): string
    {
        return 'rows';
    }

    public function label(): string
    {
        return 'Rows';
    }

    public function resolve(DataRange $range): ListResult
    {
        return new ListResult(new ListRow('Oslo', $range->seconds()));
    }
}

class UntypedSource implements DataSource
{
    public function key(): string
    {
        return 'untyped';
    }

    public function label(): string
    {
        return 'Untyped';
    }

    public function resolve(DataRange $range): DataSourceResult
    {
        return new NumberResult(1);
    }
}

class BrokenSource extends ContainerMadeSource
{
    public function key(): string
    {
        return 'broken';
    }

    public function resolve(DataRange $range): NumberResult
    {
        throw new RuntimeException('the database password is hunter2');
    }
}

class CountingMiddleware
{
    public static int $seen = 0;

    public function handle(Request $request, Closure $next): mixed
    {
        self::$seen++;

        return $next($request);
    }
}

const PULL_TOKEN = 'lj_pull_test';

beforeEach(function () {
    $this->withConfig([
        'lumberjack.data_sources.enabled' => true,
        'lumberjack.data_sources.pull_token' => PULL_TOKEN,
        'lumberjack.data_sources.sources' => [ContainerMadeSource::class, CachedSource::class, RowsSource::class, BrokenSource::class],
        'app.url' => 'https://app.test',
    ]);
    $this->pull = function (array $query, array $change = [], string $token = PULL_TOKEN, string $url = 'https://app.test/lumberjack/datasources') {
        $headers = PullSignature::headers($token, $url, $query, $change['timestamp'] ?? null);
        $server = ($change['server'] ?? []) + [
            'HTTP_X_LUMBERJACK_TIMESTAMP' => $headers['X-Lumberjack-Timestamp'],
            'HTTP_X_LUMBERJACK_SIGNATURE' => $headers['X-Lumberjack-Signature'],
        ];

        return $this->call('GET', ($change['target'] ?? $url).'?'.http_build_query($change['send'] ?? $query), [], [], [], $server);
    };
    $this->query = ['from' => 1_000, 'only' => 'made', 'to' => 4_600];
});

describe('registering data sources', function () {
    it('makes a class through the container, only when it is needed', function () {
        $this->app->forgetInstance(CountingDependency::class);
        $registry = app(DataSourceRegistry::class);
        $made = false;
        $this->app->resolving(CountingDependency::class, function () use (&$made): void {
            $made = true;
        });

        Lumberjack::dataSources()->add(new class (new CountingDependency()) extends ContainerMadeSource {
            public function key(): string
            {
                return 'extra';
            }
        });

        expect($made)->toBeFalse()
            ->and($registry->all())->toHaveKeys(['made', 'extra', 'cached'])
            ->and($made)->toBeTrue();
    });

    it('refuses what is no data source, at the line that is wrong', function () {
        expect(fn () => Lumberjack::dataSources()->add(stdClass::class))->toThrow(InvalidDataSourceException::class);
    });
});

describe('the signature', function () {
    it('matches the format the server signs with', function () {
        expect(PullSignature::sign('lj_pull_test', 'get', 'App.Example.com', '/lumberjack/data-sources', ['to' => 2000, 'only' => 'a,b', 'from' => 1000], 1762700000))
            ->toBe('v1=5993b30d24afe96048e16cceab551cd866b7e625357ef76f61d629eeb9e22d2d');
    });
});

describe('who gets an answer', function () {
    it('answers a correctly signed request', function () {
        ($this->pull)($this->query)->assertOk()->assertJsonPath('version', 1)->assertJsonPath('sources.made.shape', 'number');
    });

    it('answers everything else with a 404', function (Closure $request) {
        $request->call($this)->assertNotFound();
    })->with([
        'no signature' => [fn () => $this->call('GET', 'https://app.test/lumberjack/datasources?only=made')],
        'another token' => [fn () => ($this->pull)($this->query, token: 'wrong')],
        'plain http' => [fn () => ($this->pull)($this->query, ['target' => 'http://app.test/lumberjack/datasources'])],
        'an old timestamp' => [fn () => ($this->pull)($this->query, ['timestamp' => time() - 61])],
        'a changed query' => [fn () => ($this->pull)($this->query, ['send' => [...$this->query, 'only' => 'cached']])],
        'an extra parameter' => [fn () => ($this->pull)($this->query, ['send' => [...$this->query, 'x' => '1']])],
        'a signature for another host' => [fn () => ($this->pull)($this->query, url: 'https://evil.test/lumberjack/datasources', change: ['target' => 'https://app.test/lumberjack/datasources'])],
    ]);

    it('refuses the same signature twice', function () {
        $time = time();
        ($this->pull)($this->query, ['timestamp' => $time])->assertOk();
        ($this->pull)($this->query, ['timestamp' => $time])->assertNotFound();
    });

    it('has no route unless it is turned on', function () {
        $this->withConfig(['lumberjack.data_sources.enabled' => false, 'lumberjack.data_sources.pull_token' => PULL_TOKEN]);

        expect(Route::has('lumberjack.data-sources'))->toBeFalse();
    });

    it('answers nothing without a token', function () {
        config(['lumberjack.data_sources.pull_token' => null]);

        ($this->pull)($this->query)->assertNotFound();
    });
});

describe('the configured endpoint', function () {
    it('moves to the configured path and runs extra middleware after the signature', function () {
        CountingMiddleware::$seen = 0;
        $this->withConfig([
            'lumberjack.data_sources.enabled' => true,
        'lumberjack.data_sources.pull_token' => PULL_TOKEN,
            'lumberjack.data_sources.path' => 'internal/feed',
            'app.url' => 'https://app.test',
            'lumberjack.data_sources.middleware' => [CountingMiddleware::class],
            'lumberjack.data_sources.sources' => [ContainerMadeSource::class],
        ]);
        $url = 'https://app.test/internal/feed';

        $this->call('GET', $url.'?only=made')->assertNotFound();
        expect(CountingMiddleware::$seen)->toBe(0);

        ($this->pull)($this->query, url: $url)->assertOk();
        expect(CountingMiddleware::$seen)->toBe(1);
    });
});

describe('what the endpoint answers', function () {
    it('resolves only what is asked for', function () {
        ($this->pull)($this->query)->assertJsonCount(1, 'sources');
    });

    it('says what there is without only, resolving nothing', function () {
        ($this->pull)([])->assertExactJson(['version' => 1, 'complete' => true, 'sources' => [
            ['key' => 'made', 'label' => 'Made by the container', 'shape' => 'number', 'cache' => null],
            ['key' => 'cached', 'label' => 'Made by the container', 'shape' => 'number', 'cache' => 300],
            ['key' => 'rows', 'label' => 'Rows', 'shape' => 'list', 'cache' => null],
            ['key' => 'broken', 'label' => 'Made by the container', 'shape' => 'number', 'cache' => null],
        ]]);

        expect(app(CountingDependency::class)->calls)->toBe(0);
    });

    it('says the list is not whole when a label throws, and still lists the rest', function () {
        app(DataSourceRegistry::class)->add(new class () extends RowsSource {
            public function key(): string
            {
                return 'bad-label';
            }

            public function label(): string
            {
                throw new RuntimeException('no');
            }
        });

        ($this->pull)([])->assertJsonPath('complete', false)->assertJsonCount(4, 'sources');
    });

    it('keeps what a source gives to its shape', function () {
        ($this->pull)([...$this->query, 'only' => 'rows'])->assertExactJson(['version' => 1, 'sources' => ['rows' => ['shape' => 'list', 'data' => [['label' => 'Oslo', 'value' => 3600]]]]]);
    });

    it('costs a failing or unknown source only its own entry, and never says why', function () {
        expect(($this->pull)([...$this->query, 'only' => 'broken'])->assertOk()->json('sources.broken'))->toBe(['error' => 'failed'])
            ->and(($this->pull)([...$this->query, 'only' => 'nope'])->assertOk()->json('sources.nope'))->toBe(['error' => 'unknown source']);
    });

    it('caches a source with the attribute per range, and no other', function () {
        $query = [...$this->query, 'only' => 'cached'];
        expect(($this->pull)($query)->json('sources.cached.data'))->toBe(1);
        expect(($this->pull)($query, ['timestamp' => time() + 1])->json('sources.cached.data'))->toBe(1);
        expect(($this->pull)([...$query, 'to' => 4_700])->json('sources.cached.data'))->toBe(2);
        expect(Cache::has('lumberjack:data-source:cached:number:1000:4600'))->toBeTrue();
    });

    it('refuses more sources than one call may ask for, and a range that makes no sense', function (array $query) {
        ($this->pull)($query)->assertStatus(400);
    })->with([
        'too many' => [['from' => 1_000, 'only' => 'made,rows', 'to' => 4_600]],
        'backwards' => [['from' => 4_600, 'only' => 'made', 'to' => 1_000]],
        'not times' => [['from' => 'yesterday', 'only' => 'made', 'to' => 1_000]],
    ]);
});

describe('results', function () {
    it('gives what goes over the wire', function (DataSourceResult $result, mixed $expected) {
        expect($result->data())->toBe($expected);
    })->with([
        'a number' => fn () => [new NumberResult(42), 42],
        'no number' => fn () => [new NumberResult(null), null],
        'a series' => fn () => [new SeriesResult(new SeriesPoint(CarbonImmutable::createFromTimestamp(1767225600), 1)), [['at' => 1767225600, 'value' => 1]]],
        'a list' => fn () => [new ListResult(new ListRow('Oslo', 3)), [['label' => 'Oslo', 'value' => 3]]],
        'a table' => fn () => [new TableResult(['A'], [[true]]), ['columns' => ['A'], 'rows' => [['true']]]],
    ]);

    it('refuses what it cannot carry', function (Closure $build) {
        expect($build)->toThrow(InvalidDataSourceResultException::class);
    })->with([
        'a number JSON has no form for' => [fn () => new NumberResult(INF)],
        'too many points' => [fn () => new SeriesResult(...array_fill(0, 1001, new SeriesPoint(1, 1)))],
        'too many rows' => [fn () => new ListResult(...array_fill(0, 101, new ListRow('a', 1)))],
        'a row too short' => [fn () => new TableResult(['A', 'B'], [[1]])],
        'a time before 1970' => [fn () => new SeriesPoint(-1, 1)],
        'keyed cells' => [fn () => new TableResult(['A'], [['x' => 1]])],
    ]);
});

describe('the shape of a source', function () {
    it('follows from the return type of resolve', function () {
        expect(Shape::of(RowsSource::class))->toBe(Shape::List)
            ->and(Shape::of(ContainerMadeSource::class))->toBe(Shape::Number);
    });

    it('follows from the return type of a closure', function () {
        app(DataSourceRegistry::class)->define('a', 'A', fn (DataRange $range): TableResult => new TableResult([], []));

        expect(Shape::of(app(DataSourceRegistry::class)->all()['a']))->toBe(Shape::Table);
    });

    it('cannot be left open', function () {
        expect(fn () => app(DataSourceRegistry::class)->add(UntypedSource::class))->toThrow(InvalidDataSourceException::class)
            ->and(fn () => app(DataSourceRegistry::class)->define('a', 'A', fn (DataRange $range) => new NumberResult(1)))->toThrow(InvalidDataSourceException::class);
    });
});

describe('where it is', function () {
    it('is the app and the path', function () {
        expect(DataSourceEndpoint::url())->toBe('https://app.test/lumberjack/datasources');
    });
});

describe('sources from a closure', function () {
    it('answers with the range, and keeps the answer per range when told to', function () {
        $calls = 0;
        app(DataSourceRegistry::class)->define('orders:total', 'Orders', function (DataRange $range) use (&$calls): NumberResult {
            $calls++;

            return new NumberResult($range->seconds());
        }, cacheFor: 60);
        $range = new DataRange(CarbonImmutable::createFromTimestamp(1000), CarbonImmutable::createFromTimestamp(1600));

        app(DataSourceResolver::class)->resolve(['orders:total'], $range);
        $answers = app(DataSourceResolver::class)->resolve(['orders:total'], $range);

        expect($answers['orders:total'])->toBe(['shape' => 'number', 'data' => 600])->and($calls)->toBe(1);
    });
});

describe('what the review found', function () {
    it('answers 404 to every other method, signed or not', function (string $method) {
        $this->call($method, 'https://app.test/lumberjack/datasources')->assertNotFound();
    })->with(['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'TRACE', 'CONNECT']);

    it('answers a JSON client as a missing path would', function () {
        $this->getJson('https://app.test/lumberjack/datasources')->assertNotFound()->assertJsonPath('message', 'The route lumberjack/datasources could not be found.');
    });

    it('does not take the app down on something that is not a source at all', function () {
        app()->instance('env', 'production');

        app(DataSourceRegistry::class)->add(null, 123);

        expect(app(DataSourceRegistry::class)->isComplete())->toBeFalse();
    });

    it('says the list is not whole when a source was refused', function () {
        app()->instance('env', 'production');

        app(DataSourceRegistry::class)->add(UntypedSource::class);

        ($this->pull)([])->assertJsonPath('complete', false);
    });

    it('records no pull as a request of the app', function () {
        $fake = Lumberjack::fake();

        ($this->pull)([])->assertOk();

        $fake->assertNotRecorded(EventType::Request);
    });

    it('answers a signed only with no key with a 400', function () {
        ($this->pull)(['from' => 1000, 'only' => '', 'to' => 2000])->assertStatus(400);
    });

    it('ignores a Host the request claims', function () {
        $query = ['from' => 1000, 'only' => 'made', 'to' => 2000];

        $this->withHeaders(PullSignature::headers(PULL_TOKEN, 'https://other.test/lumberjack/datasources', $query))
            ->get('https://other.test/lumberjack/datasources?'.http_build_query($query))
            ->assertNotFound();
    });

    it('answers 400 to parameters that are not plain values', function (string $query) {
        parse_str($query, $parsed);

        $this->withHeaders(PullSignature::headers(PULL_TOKEN, 'https://app.test/lumberjack/datasources', $parsed))
            ->get('https://app.test/lumberjack/datasources?'.$query)
            ->assertStatus(400);
    })->with(['an array' => ['from=1&only[]=x&to=2'], 'a huge time' => ['from=1&only=made&to=99999999999999999']]);

    it('can pull a source keyed 0', function () {
        app(DataSourceRegistry::class)->define('0', 'Zero', fn (DataRange $range): NumberResult => new NumberResult(1));

        ($this->pull)(['from' => 1000, 'only' => '0', 'to' => 2000])->assertJsonPath('sources.0.data', 1);
    });

    it('does not take the app down outside local and testing', function () {
        app()->instance('env', 'production');

        app(DataSourceRegistry::class)->add(UntypedSource::class, 'Not\\A\\Class')->define('b', 'B', fn (DataRange $range) => 1);

        expect(array_keys(app(DataSourceRegistry::class)->all()))->not->toContain('untyped', 'b');
    });

    it('keeps a CacheFor from a parent class', function () {
        $child = new class (new CountingDependency()) extends CachedSource {};

        expect(DataSourceResolver::cacheSeconds($child))->toBe(300);
    });

    it('refuses a key with a trailing newline', function () {
        expect(preg_match(DataSourceRegistry::KEY_PATTERN, "abc\n"))->toBe(0);
    });
});

describe('lumberjack:data-sources:test', function () {
    beforeEach(function () {
        config(['app.url' => 'https://app.test', 'lumberjack.data_sources.enabled' => true, 'lumberjack.data_sources.pull_token' => str_repeat('a', 40)]);
        app(DataSourceRegistry::class)->define('total', 'Total', fn (DataRange $range): NumberResult => new NumberResult(3));
    });

    it('does not take any 200 for the endpoint', function () {
        Http::fake(['*' => Http::response('<html>parked</html>')]);

        $this->artisan('lumberjack:data-sources:test')->expectsOutputToContain('something else answers')->assertFailed();
    });

    it('names a cache that cannot remember a pull', function () {
        config(['cache.default' => 'null', 'cache.stores.null' => ['driver' => 'null']]);

        $this->artisan('lumberjack:data-sources:test')->expectsOutputToContain('CACHE_STORE')->assertFailed();
    });

    it('reads a dotted key as one key', function () {
        config(['lumberjack.data_sources.sources' => []]);
        $this->app->forgetInstance(DataSourceRegistry::class);
        app(DataSourceRegistry::class)->define('orders.total', 'Orders', fn (DataRange $range): NumberResult => new NumberResult(3));
        Http::fake(['*' => Http::response(['version' => 1, 'sources' => ['orders.total' => ['shape' => 'number', 'data' => 3]]])]);

        $this->artisan('lumberjack:data-sources:test')->expectsOutputToContain('answers a signed request')->assertSuccessful();
    });

    it('checks https with no sources registered', function () {
        config(['app.url' => 'http://localhost', 'lumberjack.data_sources.sources' => []]);
        $this->app->forgetInstance(DataSourceRegistry::class);

        $this->artisan('lumberjack:data-sources:test')->expectsOutputToContain('is not https')->assertFailed();
    });

    it('resolves on http when told to skip the call', function () {
        config(['app.url' => 'http://localhost']);

        $this->artisan('lumberjack:data-sources:test', ['--skip-http' => true])->expectsOutputToContain('ok, number')->doesntExpectOutputToContain('is not https');
    });

    it('says why a source failed', function () {
        app(DataSourceRegistry::class)->define('bad', 'Bad', fn (DataRange $range): NumberResult => throw new RuntimeException('the reason'));

        $this->artisan('lumberjack:data-sources:test', ['--skip-http' => true])->expectsOutputToContain('the reason')->assertFailed();
    });
});

it('refuses a callable it cannot use without taking the app down', function () {
    app()->detectEnvironment(fn (): string => 'production');

    app(DataSourceRegistry::class)->define('a', 'A', 'no_such_function');

    expect(app(DataSourceRegistry::class)->isComplete())->toBeFalse();
});
