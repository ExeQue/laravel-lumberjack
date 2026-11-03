# Testing your app

The package has no fake. In tests no agent runs, so what the app records is dropped. Turn
recording off to skip the work, and keep health state reads from calling Lumberjack:

```xml
<!-- phpunit.xml -->
<env name="LUMBERJACK_ENABLED" value="false"/>
<env name="LUMBERJACK_HEALTH_STATES_PULL" value="false"/>
<env name="LUMBERJACK_HEALTH_COOLDOWN" value="0"/>
```

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
