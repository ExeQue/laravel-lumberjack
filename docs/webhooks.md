# Reacting to alerts

Lumberjack can send its alerts to your app, so the app can react: turn on a status banner while payments are failing, page someone, or pause a feature. The package receives them and runs the handlers you register.

## Setting it up

1. In Lumberjack, open the project's **Settings**, **Alerts**, and add a **Webhook** channel with your app's URL, e.g. `https://your-app.example.com/lumberjack/webhook`. Lumberjack shows its signing secret once.
2. In your app's `.env`:

   ```dotenv
   LUMBERJACK_WEBHOOK_ENABLED=true
   LUMBERJACK_WEBHOOK_SECRET=whsec_...
   ```

3. Pick which alerts go to the webhook in the project's rules, on the same page.

The route exists only while `LUMBERJACK_WEBHOOK_ENABLED` is on, on the path `LUMBERJACK_WEBHOOK_PATH` gives (`lumberjack/webhook` by default). It sits outside the `web` middleware group, so there is no session and no CSRF token: the signature is the check.

## Handlers

Register what should happen in a service provider:

```php
use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\Webhooks\Alert;
use ExeQue\Lumberjack\Webhooks\Status;

public function boot(): void
{
    // Exception classes match their subclasses too.
    Lumberjack::onException(PaymentGatewayException::class, [Status::New, Status::Reopened, Status::Escalating], function (Alert $alert) {
        StatusBanner::show('Payments are slow right now', until: now()->addHour());
    });

    // Any issue.
    Lumberjack::on(Status::Resolved, fn (Alert $alert) => StatusBanner::hideFor($alert->title));
}
```

Handlers run in the webhook's request; dispatch a job from them for anything slow. Every alert also fires `ExeQue\Lumberjack\Webhooks\AlertReceived`, for a listener that wants them all.

### Statuses

| Status | When |
|---|---|
| `New` | The first occurrence of a new issue |
| `Reopened` | A resolved issue happened again, or an ignored one came back |
| `Escalating` | An open issue suddenly happens far more often than usual |
| `Resolved` | Someone resolved the issue |
| `Ignored` | Someone ignored the issue |
| `Note` | Someone wrote a note on the issue |

### The alert

`Alert` carries:

| Property | |
|---|---|
| `status` | The `Status` above |
| `issueId`, `reference` | The issue's id, and its number such as `SHOP-7` |
| `title`, `message` | For an exception, its class and message |
| `url` | The issue in Lumberjack |
| `environment` | Where it happened |
| `burst` | For an escalation: the occurrences in the last 5 minutes |
| `note`, `by` | The note, or who changed the status |
| `payload` | The whole webhook body |

`$alert->isException(PaymentGatewayException::class)` tells whether the issue is that class or a subclass.

## Signatures

Each request carries:

- `X-Lumberjack-Timestamp`: when it was sent, in Unix seconds,
- `X-Lumberjack-Signature`: `sha256=` and the HMAC-SHA256 of `"{timestamp}.{body}"` with the channel's secret.

The package recomputes the HMAC, compares in constant time and refuses a request more than 5 minutes old, so a captured one cannot be replayed. A request that fails any of this, or arrives while no secret is set, gets a **404**, as if the path did not exist. Lumberjack then shows the channel's delivery as failed with HTTP 404.

Receiving the webhook without this package: check the same two headers the same way.

## The body

```json
{
  "version": "1",
  "sent_at": "2026-11-02T18:40:00+00:00",
  "event": "escalation",
  "project": { "id": "01a1...", "slug": "shop", "name": "Shop" },
  "environment": "production",
  "issue": {
    "id": "01a2...", "reference": "SHOP-7", "title": "App\\Exceptions\\PaymentGatewayException",
    "message": "Card processor timed out", "status": "open", "source": "server", "escalating": true,
    "occurrences": 512, "first_seen_at": "...", "last_seen_at": "...", "url": "https://lumberjack.example.com/..."
  },
  "burst": 42,
  "status_change": null,
  "note": null
}
```

`event` is one of `new_issue`, `regression`, `escalation`, `note`, `status_change`, `queue_backlog` or `test`. The **Test** button on the channel sends a `test` event, which the package accepts and does nothing with. A `queue_backlog` event (a queue over its thresholds, or back under) carries a `queue` instead of an `issue`; the package accepts it without running handlers, so read it from your own route if you need it. Its body is described in Lumberjack's alerts documentation.
