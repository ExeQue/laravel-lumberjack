# Testing your app

In tests no agent runs, so what the app records is dropped. Turn
recording off to skip the work, and keep health state reads from calling Lumberjack:

```xml
<!-- phpunit.xml -->
<env name="LUMBERJACK_ENABLED" value="false"/>
<env name="LUMBERJACK_HEALTH_STATES_PULL" value="false"/>
<env name="LUMBERJACK_HEALTH_COOLDOWN" value="0"/>
```

## Asserting on what is recorded

`Lumberjack::fake()` keeps everything the app would have sent, so a test can assert on
it. Nothing leaves the test, and it works with `LUMBERJACK_ENABLED=false`.

```php
use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\Testing\EventType;

Lumberjack::fake();

// run the code under test

Lumberjack::assertRecorded(EventType::Job);
Lumberjack::assertRecorded(EventType::Log, ['level' => 'error']);
Lumberjack::assertRecorded(EventType::Log, fn (array $event) => str_contains($event['message'], 'paid'));
Lumberjack::assertRecorded(EventType::Query, times: 2);
Lumberjack::assertNotRecorded(EventType::Job, ['status' => 'failed']);
Lumberjack::assertNothingRecorded();
```

Match with an array of values, where keys can use dot notation (`context.order_id`), or
with a closure for anything else. Without `times`, one or more is enough.

Each event is an array, as Lumberjack would receive it: scrubbed, and sampled like in
production. `Lumberjack::recorded(EventType::Log)` returns them. The
[recording rules](recording.md) still apply. A failing assertion lists what was recorded.

`artisan down` and `artisan up` are recorded as `EventType::Maintenance`; under `fake()` they
are not sent to Lumberjack.

With `LUMBERJACK_ENABLED=false`, the `lumberjack` log channel stays silent, so to assert on
`log` events, keep recording on for that test.

### Exceptions

```php
Lumberjack::assertReported(PaymentFailed::class);
Lumberjack::assertReported(PaymentFailed::class, 'Card declined', times: 1);
Lumberjack::assertNotReported(PaymentFailed::class);
```

The class or one extending it counts.

### Custom alerts

[Custom alerts](alerts.md) can be matched by title, topic and level, in any combination:

```php
Lumberjack::assertAlerted('Payment failed');
Lumberjack::assertAlerted('Payment failed', topic: 'payments', level: 'critical');
Lumberjack::assertAlerted(topic: 'payments', times: 2);
Lumberjack::assertAlerted(fn (RecordedAlert $alert) => count($alert->links) === 2);
Lumberjack::assertNotAlerted('Payment received');
Lumberjack::assertNothingAlerted();
```

A `RecordedAlert` has `title`, `topic`, `level`, `message`, `url`, `fields`, `tables`,
`links`, `dedupeKey` and `dedupeMinutes`. Without a topic or a level, they are `default`
and `info`, as on the server. `Lumberjack::alerts()` returns them.

### Activity

[Activity](activity.md) can be matched by name and group, or with a check:

```php
Lumberjack::assertActivityRecorded('order.placed');
Lumberjack::assertActivityRecorded('order.placed', group: 'billing', times: 1);
Lumberjack::assertActivityRecorded(fn (RecordedActivity $activity) => $activity->isBy(User::class, $user->id));
Lumberjack::assertActivityNotRecorded('order.refunded');
Lumberjack::assertActorForgotten($user);
```

A `RecordedActivity` has `name`, `group`, `actor`, `subject` and `payload`, and `isBy()`
and `isOn()` to compare the actor or subject with a type and key. Without a group it is
`default`, as on the server. `Lumberjack::activities()` returns them. Under the fake,
`Lumberjack::forgetActor()` asks nobody and returns 0.

### Assertions in a closure

Type a closure with an assertable class and it makes assertions rather than answering true or
false. `times` counts the records that pass them all, and a failure shows the closest one:

```php
use ExeQue\Lumberjack\Testing\Assertable\{AssertableAlert, AssertableEvent, AssertableReport};

Lumberjack::assertRecorded(EventType::Job, fn (AssertableEvent $event) => $event
    ->has('queue', 'default')
    ->has('name')
    ->missing('exception')
    ->where('duration_ms', fn ($ms) => $ms < 500));

Lumberjack::assertAlerted(fn (AssertableAlert $alert) => $alert
    ->hasTitle('Import done')
    ->toTopic('imports')
    ->isCritical()
    ->hasField('Rows', '1200')
    ->hasLink('Open'));

Lumberjack::assertReported(PaymentFailed::class, fn (AssertableReport $report) => $report
    ->hasMessage('Card declined')
    ->hasContext('order_id', 42));
```

```
Expected a [job] event passing the check, but found none.
Closest of 3:
  queue  "default"
> status  "processed" (expected "failed")
```

`assertNotRecorded()`, `assertNotAlerted()` and `assertNotReported()` take them too. An alert also has
`hasLevel()`, `isWarning()`, `hasMessage()`, `hasUrl()` and `hasDedupeKey()`. A closure without such
a type goes on answering true or false.

## Health checks

Make your own check and assert on its result:

```php
use ExeQue\Lumberjack\Health\CheckStatus;

expect(app(LicenseServerCheck::class)->run()->status)->toBe(CheckStatus::Ok);
```

List the slugs that are registered:

```php
use ExeQue\Lumberjack\Health\HealthRegistry;

expect(array_keys(app(HealthRegistry::class)->checks()))->toContain('disk:s3');
```

Give your code a check state to react to. This also dispatches `HealthStateChanged`:

```php
use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\Health\HealthStates;

app(HealthStates::class)->fill(['checks' => [
    ['slug' => 'disk:s3', 'state' => 'down', 'message' => 'Bucket gone'],
]]);

expect(Lumberjack::health('disk:s3')->isHealthy())->toBeFalse();
```

## Data sources

A source written as a class can be called directly, with the range you want to test. One from `define()` is tested with `dataSource('key')`, below.

Add `InteractsWithDataSources` to a test case, and `$this->dataSource()` takes a registered key, a class or an instance. It runs `resolve()` itself, once per range and past any cache, so what a source throws reaches the test.

```php
use ExeQue\Lumberjack\Testing\InteractsWithDataSources;

uses(InteractsWithDataSources::class);

it('counts the orders of the period', function () {
    Order::factory()->create(['created_at' => now()->subHours(2)]);
    Order::factory()->create(['created_at' => now()->subDays(3)]);

    $this->dataSource('orders:total')
        ->forLast(hours: 24)
        ->assertNumber(1)
        ->assertCachedFor(300)
        ->assertRegistered();
});
```

The range is the last 24 hours unless `forLast()` or `between($from, $to)` says otherwise. `assertEmpty()`, `assertRowCount()`, `assertNotCached()`, `result()` and `data()` work on every shape. `assertSeries()`, `assertRows()` and `assertTable()` take the whole expected data, or a closure with assertions:

```php
$from = CarbonImmutable::parse('2026-11-09 08:00', 'UTC');
$to = $from->addHours(6);

$this->dataSource('orders:per-hour')->between($from, $to)->assertSeries(fn (AssertableSeries $series) => $series
    ->hasPoints(6)->isChronological()->isWithinRange()->isEvery(hours: 1)->coversRange()
    ->startsAt($from)->hasValueAt($from->addHours(2), 3)->hasNoValueAt($from->addHours(3))
    ->sumsTo(12)->maxIs(3)->peaksAt($from->addHours(2))->averages(2.4)
    ->each(fn (SeriesPoint $point) => $point->value >= 0));

$this->dataSource('orders:per-hour')->assertSeries(['2026-11-09 10:00' => 1, '2026-11-09 11:00' => null]);

$this->dataSource('orders:by-city')->assertRows(fn (AssertableList $rows) => $rows
    ->hasRows(3)->startsWith('Oslo')->isSortedByValue(descending: true)
    ->hasRow('Bergen', 7)->missingLabel('Tromsø')->sumsTo(30));

$this->dataSource('orders:by-city')->assertRows(['Oslo' => 20, 'Bergen' => 7]);

$this->dataSource('orders:report')->assertTable(fn (AssertableTable $table) => $table
    ->hasColumns(['City', 'Orders'])->hasRow(['Oslo', 20])
    ->hasRowWhere('City', 'Oslo', ['Orders' => 20])
    ->column('Orders', fn (AssertableColumn $column) => $column->isNumeric()->sumsTo(27)->isSorted(descending: true))
    ->eachRow(fn (array $row) => $row['Orders'] > 0));

$this->dataSource('orders:report')->assertTable(['City', 'Orders'], [['Oslo', 20]]);
```

By position, where a negative one counts from the end: `pointIs(0, $at, 3)`, `timeIs()` and `valueIs()` on a series, `rowIs(0, 'Oslo', 20)`, `labelIs()` and `valueIs()` on a list, `rowIs(-1, ['Oslo', 20])` and `cellIs(0, 'Orders', 20)` on a table.

A failure shows the data with the wrong row marked. Times are a date, a Unix time or a string read as UTC, and compared as moments.

`pull()` goes through the real, signed endpoint with the app's middleware, and gives a `TestResponse`. It needs `LUMBERJACK_ENABLED=true` and `LUMBERJACK_DATA_SOURCES_ENABLED=true` in the test environment, as the route is made at boot. A 200 can still carry an `error` for the source, so assert on its data:

```php
$this->dataSource('orders:total')->pull()->assertOk()->assertJsonPath('sources.orders:total.data', 1);
```

## Webhook handlers

Run the handlers for an alert:

```php
use ExeQue\Lumberjack\Webhooks\Alert;
use ExeQue\Lumberjack\Webhooks\Handlers;
use ExeQue\Lumberjack\Webhooks\Status;

app(Handlers::class)->handle(Alert::fromPayload(Status::New, $payload)); // how many handlers ran
```

Or post a signed request to the route, as Lumberjack does: `X-Lumberjack-Timestamp`, and
`X-Lumberjack-Signature` set to `sha256=` and the HMAC-SHA256 of `{timestamp}.{body}`
with the secret. See [Signatures](webhooks.md#signatures).
