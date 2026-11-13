<!-- Part of the Lumberjack for Laravel documentation - see ../README.md -->

# Activity

Record what happens in your app, such as an order placed or a user signed in. Lumberjack
lists each activity on its Events page with who did it and what it was done to, and
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

| Method                    | Sets                                                                                      |
| ------------------------- | ----------------------------------------------------------------------------------------- |
| `activity($name)`         | The name: letters, digits, `.`, `_`, `:` and `-`, at most 128 characters.                 |
| `group($group)`           | The group it is counted in. Without one, `default`.                                       |
| `byUser($guard = null)`   | The logged in user as the actor. Without a logged in user it has no actor.               |
| `by($model)`              | Who did it: any model, such as a team.                                                    |
| `by($type, $id)`          | Who did it, when it is not a model: `by('system', 'scheduler')`.                          |
| `on($model)`, `on($t, $id)` | What it was done to.                                                                    |
| `with($payload)`          | What else to keep, merged into what is there. At most 16 KB as JSON.                      |
| `send()`                  | Records it. It goes out with the rest of the request or job.                              |

Nothing is sent before `send()`, and `when()` works on every method.

### No personal data by default

An actor or a subject is sent as its type and key only. The type is the model's morph
alias, or its class without one. Nobody is the actor unless the code says so. A model
in the payload is sent the same way, never its attributes. Dates are sent as ISO 8601,
enums as their value, and anything `Arrayable` as its array. The payload is scrubbed like
everything else, see [What is recorded](recording.md).

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
    subject: 'order:@order.uuid',
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
- **Actor and subject:** `@path` to a model, or `type:id`, whose id may be a path too
  (`order:@order.uuid`).
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
Lumberjack::forgetActor($user);           // a model
Lumberjack::forgetActor('user', 17);      // or a type and its key
```

It asks Lumberjack right away with the server token (`LUMBERJACK_URL` and
`LUMBERJACK_TOKEN`) and returns how many activities were deleted. Unlike recording, it
throws a `ForgetActorFailedException` when Lumberjack did not do it, so the deletion can be
retried, for example from a queued job. The hourly counts stay; they never held the actor.

## Testing

See [Testing your app](testing.md#activity).
