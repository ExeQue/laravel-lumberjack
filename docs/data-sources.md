<!-- Part of the Lumberjack for Laravel documentation - see ../README.md -->

# Data sources

A data source is a figure, a series, a list or a table your app offers Lumberjack's status
screens. You register it, tell Lumberjack once where the app is, and Lumberjack pulls the rest. The
screen showing a source decides which period it is asked for. Nothing goes through the agent.

## Set it up

1. In Lumberjack, open the project's **Data sources** page and click **Connect app**. Give the
   environment and the address: `https://your-app.example/lumberjack/datasources`. The pull
   token is shown once.
2. Put it in the app's `.env`, and make sure the app is reached over https:

    ```dotenv
    LUMBERJACK_DATA_SOURCES_ENABLED=true
    LUMBERJACK_PULL_TOKEN=lj_pull_...
    ```

3. Write a data source and register it:

    ```php
    use App\DataSources\TotalMediaDataSource;
    use ExeQue\Lumberjack\Facades\Lumberjack;

    public function boot(): void
    {
        Lumberjack::dataSources()->add(TotalMediaDataSource::class);
    }
    ```

4. Check it:

    ```bash
    php artisan lumberjack:data-sources:test
    ```

Lumberjack asks the app every 5 minutes which sources it has. Until the app is deployed with
the token the connection reads **Waiting for the app**, which is not an error. After that the
sources are listed on the Data sources page, and widgets can pick them.

## The contract

```php
namespace ExeQue\Lumberjack\Contracts;

interface DataSource
{
    /** What Lumberjack knows it by. Keep it stable. 1 to 100 of a-z, 0-9, ":", ".", "_", "-". */
    public function key(): string;

    /** What people pick it by in Lumberjack. */
    public function label(): string;

    /** The data for this range. Declare the result class you return. */
    public function resolve(DataRange $range): DataSourceResult;
}
```

A full example, with its dependencies from the container:

```php
use ExeQue\Lumberjack\Contracts\DataSource;
use ExeQue\Lumberjack\DataSources\CacheFor;
use ExeQue\Lumberjack\DataSources\DataRange;
use ExeQue\Lumberjack\DataSources\Results\NumberResult;

#[CacheFor(seconds: 300)]
class TotalMediaDataSource implements DataSource
{
    public function __construct(private readonly MediaStatistics $statistics)
    {
    }

    public function key(): string
    {
        return 'total-media';
    }

    public function label(): string
    {
        return 'Total media';
    }

    public function resolve(DataRange $range): NumberResult
    {
        return new NumberResult($this->statistics->uploadedBetween($range->from, $range->to));
    }
}
```

`DataRange` has `from` and `to` as `CarbonImmutable` in UTC, and `seconds()`. A source that
has no use for a range, a count of everything so far, leaves it unread.

## Registering

`add()` takes instances and classes, as many as you like:

```php
Lumberjack::dataSources()->add(
    TotalMediaDataSource::class,
    new SignupsDataSource('eu'),
);
```

A class is made by the service container the first time it is needed, so registering one
costs a request nothing and it can type-hint what it depends on. They can also go in
`lumberjack.data_sources.sources`.

A value that is no source throws at the line that added it in `local` and `testing`; a class is only made when read, so a bad key from one is reported and left out. Run `lumberjack:data-sources:test` to see it. Everywhere else, and
for entries in the config, it is reported and left out, as registering runs in the app's boot.
A class that cannot be made, or a key used twice, is reported and left out, without costing the
other sources. The first pull in a process makes every class, as a key is only known from an instance.

A source too small for a class of its own is a closure. It gets the `DataRange`, declares
the result class it returns like `resolve()` does, and `cacheFor` does what `#[CacheFor]` does:

```php
use ExeQue\Lumberjack\DataSources\DataRange;
use ExeQue\Lumberjack\DataSources\Results\NumberResult;

Lumberjack::dataSources()->define(
    'orders:total',
    'Orders in the period',
    fn (DataRange $range): NumberResult => new NumberResult(Order::whereBetween('created_at', [$range->from, $range->to])->count()),
    cacheFor: 300,
);
```

## Shapes

The result class `resolve()` declares is the shape: Lumberjack reads it from the return type,
so declare the concrete class. A source that declares `DataSourceResult`, a union, a nullable
result or nothing is refused where it is added. Results are the four classes above.

Spread a collection into a result with int keys: `new ListResult(...$rows->values())`.

The shape decides which widgets can show a source. What `resolve()` gives is rebuilt from
the fields below, so anything else a query happened to select never leaves the app.

| Shape | `resolve()` gives | Widgets | Limit |
|---|---|---|---|
| `NumberResult` | `new NumberResult(42)`, or `null` for none | Stat, gauge | |
| `SeriesResult` | `new SeriesResult(new SeriesPoint($date, 3), ...)` | Chart | 1000 points |
| `ListResult` | `new ListResult(new ListRow('Oslo', 3), ...)` | List | 100 rows |
| `TableResult` | `new TableResult(['City', 'Orders'], [['Oslo', 3], ...])` | List: the first column is the label, the second the value | 100 rows, 20 columns |

`at` is a date object or a Unix time. A `value` can be `null` in a series, and also text in a list or a table cell.

Data in the wrong form fails that source, and is reported through the app's exception handler.

## Caching

Lumberjack is told how long a source is cached, pulls it no more often than that, and rounds the
range to match, so a cached answer is actually reused.

Lumberjack keeps what it pulled, and widgets never call your app while a screen is drawn. It
pulls every 5 minutes, only the sources and periods that a screen actually shows.

Add `#[CacheFor(seconds: 300)]` to a source that is expensive to resolve. The answer is kept
in the app's default cache, for each range. It is up to you how long. Without the attribute
every pull runs `resolve()`. A failure is never cached.

## The endpoint

```
GET https://app.example.com/lumberjack/datasources?from=1762610000&only=total-media&to=1762696400
```

| Parameter | |
|---|---|
| `only` | The key to resolve, one per call so a slow source costs no other. Without it nothing is resolved |
| `from`, `to` | The range, as Unix times |

```json
{
    "version": 1,
    "sources": {
        "total-media": { "shape": "number", "data": 1234 }
    }
}
```

A source that fails answers `{ "error": "failed" }`, and an unknown key `{ "error": "unknown source" }`. The answer only says that it failed: the
reason goes to the app's exception handler, and so also to Lumberjack as an issue.

Move it, or add your own middleware:

```php
'data_sources' => [
    'path' => 'internal/lumberjack-feed',
    'middleware' => ['throttle:60,1'],
],
```

The signature check runs before your configured middleware, so that never sees a request that
is not Lumberjack's (global middleware runs for every request, as always).
The address you give Lumberjack must be `APP_URL` and the path, as that is what requests are
checked against. Outside the `web` middleware group: no session, no CSRF.

## Security

The endpoint answers **404** to everything but a correct request, for every standard HTTP method, the
same as Laravel's answer to a path that does not exist. (An app with its own `Route::fallback`
answers unknown paths through that instead.) That holds for:

- no signature, a wrong one, or one made for another host
- plain http: it is always required, in every environment
- a timestamp more than 60 seconds off
- a signature already used
- a cache that cannot be reached, as replays could not be seen
- no `LUMBERJACK_PULL_TOKEN` set

With `LUMBERJACK_DATA_SOURCES_ENABLED` off, the default, or Lumberjack off altogether, there is no route at all.

Replays are only seen with a cache every server shares, so use `redis` or `database` rather
than `array` or `null`. A signed request with bad parameters gets a 400.

The pull token is its own secret. It can only read what you registered here, and it cannot
send anything to Lumberjack; the server token cannot read anything from your app. It never
travels: each request carries a signature.

```
X-Lumberjack-Timestamp: 1762696400
X-Lumberjack-Signature: v1=<hex>

HMAC-SHA256(token, "v1\nGET\n<host>\n<path>\n<query sorted by name, RFC 3986>\n<timestamp>")
```

The host and path are `APP_URL` and the path, not what a request claims. Behind a proxy, Laravel has to know that
the request was https ([trusted proxies](https://laravel.com/docs/requests#configuring-trusted-proxies)).
If not, every pull gets a 404, which is what `lumberjack:data-sources:test` is for.

What you return is yours to judge: everyone in the Lumberjack team can see it, and a screen
can be shared. Offer figures rather than people's data.

## Rotating the token

1. Click **Rotate pull token** in Lumberjack. The old one keeps working.
2. Put the new one in the app's `.env` and deploy.

Lumberjack tries the new one and falls back to the old, so it changes over by itself once the
new one is out. Rotate again only once the page is back to normal: a token that was never seen working is not kept as the old one.

## How Lumberjack finds them

Lumberjack makes the same signed `GET` without `only` every 5 minutes, which resolves nothing
and answers which sources there are:

```json
{ "version": 1, "complete": true, "sources": [
    { "key": "total-media", "label": "Total media", "shape": "number", "cache": 300 }
] }
```

A source no longer on a complete list is archived in Lumberjack and comes back if it returns.
`complete` is false when a source could not be made or described, and then nothing is archived.
Each connection in Lumberjack is one environment of the app.
