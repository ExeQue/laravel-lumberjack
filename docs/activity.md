<!-- Part of the Lumberjack for Laravel documentation - see ../README.md -->

# Activity

Record what happens in your app, such as an order placed or a user signed in. Lumberjack
lists each activity on its Activity page with who did it and what it was done to, and
counts them per hour for charts and widgets.

There are three ways to record one. All of them send the same thing.

## Lumberjack::activity()

```php
use ExeQue\Lumberjack\Facades\Lumberjack;

Lumberjack::activity('order.placed')
    ->group('billing')
    ->byUser()
    ->on($order)
    ->with(['total' => $order->total, 'currency' => 'DKK'])
    ->send();
```

An activity can be done to several things. An upload is done to the image and to the
gallery it lands in, and shows up under either on the Activity page:

```php
Lumberjack::activity('image.uploaded')->byUser()->on($image)->on($image->gallery)->send();
```

| Method                    | Sets                                                                                      |
| ------------------------- | ----------------------------------------------------------------------------------------- |
| `activity($name)`         | The name: letters, digits, `.`, `_`, `:` and `-`, at most 128 characters.                 |
| `group($group)`           | The group it is counted in. Without one, `default`.                                       |
| `byUser($guard = null)`   | The logged in user as the actor. Without a logged in user it has no actor.               |
| `by($model)`              | Who did it: any model, such as a team, or an `Actor`.                                     |
| `by($type, $id)`          | Who did it, when it is not a model: `by('system', 'scheduler')`.                          |
| `on($model)`, `on($t, $id)` | What it was done to. Call it again for each further subject, at most 10.     |
| `with($payload)`          | What else to keep, merged into what is there. At most 16 KB as JSON.                      |
| `send()`                  | Records it. It goes out with the rest of the request or job.                              |

Nothing is sent before `send()`, and `when()` works on every method.

### No personal data by default

An actor or a subject is sent as its type and key only. The type is the model's morph
alias, or its class without one. Nobody is the actor unless the code says so. A model
in the payload is sent the same way, never its attributes. Dates are sent as ISO 8601,
enums as their value, and anything `Arrayable` as its array. The payload is scrubbed like
everything else, see [What is recorded](recording.md).

### An actor that says who it is

When the type and key are not how an actor should be known, such as a user model that
stands for an API client, the class implements `ActsAsLumberjackActor` and returns an
`Actor` from `toLumberjackActor()`:

```php
use ExeQue\Lumberjack\Activity\Actor;
use ExeQue\Lumberjack\Contracts\ActsAsLumberjackActor;

class User extends Authenticatable implements ActsAsLumberjackActor
{
    public function toLumberjackActor(): Actor
    {
        return $this->is_api_client
            ? new Actor('api-client', $this->client_name)
            : new Actor('user', $this->getKey());
    }
}
```

`by()`, `byUser()`, `on()`, a `@path` in `#[RecordsLumberjackActivity]`, a model in the
payload and `forgetActor()` all use it in place of the class and key, so forgetting finds
what was recorded. `by(new Actor('system', 'scheduler'))` works too. Keep personal data out
of the key: it is sent as is.

## An event that records itself

An event of your app can record an activity every time it is dispatched, with an
attribute or with a contract.

### #[RecordsLumberjackActivity]

```php
use ExeQue\Lumberjack\Attributes\RecordsLumberjackActivity;

#[RecordsLumberjackActivity(
    'order.placed',
    group: 'billing',
    actor: '@order.customer',
    subject: ['order:@order.uuid', '@order.customer'],
    payload: [
        'total' => '@order.total',
        'items' => '@order.items.count',
        'channel' => 'web',
        'shipping' => ['express' => '@express'],
    ],
)]
final class OrderPlaced
{
    public function __construct(public Order $order, public bool $express = false) {}
}
```

- **Payload:** a value that starts with `@` is read from the event with `data_get()`.
  `@order.total` is `$event->order->total`, and properties, model attributes, relations
  and arrays all work. A path that leads nowhere gives `null`. `@@` is a literal `@`, and
  every other value is sent as written. Nested arrays are read the same way.
- **Actor and subjects:** `@path` to a model, or `type:id`, whose id may be a path too
  (`order:@order.uuid`). `subject` takes one, or a list of them.
- **`byUser: true`:** the logged in user is the actor when no actor is given.
- **Name and group** are always as written.

### TracksLumberjackActivity

For anything the attribute cannot say, implement the contract and build the activity
yourself:

```php
use ExeQue\Lumberjack\Contracts\TracksLumberjackActivity;
use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\PendingActivity;

final class OrderRefunded implements TracksLumberjackActivity
{
    public function __construct(public Order $order, public int $amount) {}

    public function toLumberjackActivity(): ?PendingActivity
    {
        if ($this->order->is_test) {
            return null;
        }

        return Lumberjack::activity('order.refunded')
            ->group('billing')
            ->byUser()
            ->on($this->order)
            ->with(['amount' => $this->amount]);
    }
}
```

Return `null` to record nothing this time. The package sends the activity, so do not
call `send()`. When a class has both the contract and the attribute, the contract is used.

## Forgetting an actor

When an account is deleted, delete its activity in Lumberjack too:

```php
Lumberjack::forgetActor($user);           // a model, or an ActsAsLumberjackActor
Lumberjack::forgetActor('user', 17);      // or a type and its key
```

It asks Lumberjack right away with the server token (`LUMBERJACK_URL` and
`LUMBERJACK_TOKEN`) and returns how many activities were deleted. Unlike recording, it
throws a `ForgetActorFailedException` when Lumberjack did not do it, so the deletion can be
retried, for example from a queued job. The hourly counts stay; they never held the actor.

## Testing

See [Testing your app](testing.md#activity).
