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
