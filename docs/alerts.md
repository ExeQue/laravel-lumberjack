<!-- Part of the Lumberjack for Laravel documentation - see ../README.md -->

# Custom alerts

Send an alert from your own code. The code names a topic; which channels the topic
reaches is picked on Lumberjack's Alerts page, so moving an alert needs no deploy.

```php
use ExeQue\Lumberjack\Facades\Lumberjack;

Lumberjack::alert('Payment received')
    ->to('payments')
    ->message('Order #1234 was paid')
    ->success()
    ->url($orderUrl)
    ->field('Amount', '499 kr', inline: true)
    ->table(['Item', 'Qty'], [['Print', '2']], title: 'Items')
    ->link('Open order', $orderUrl, primary: true)
    ->link('Refund', $refundUrl, danger: true)
    ->dedupe("order-{$order->id}", minutes: 10)
    ->send();
```

Nothing leaves until `send()`, which hands the alert to the agent right away. Every
message names the project and the environment.

| Method                                           | Notes                                                                  |
| ------------------------------------------------ | ---------------------------------------------------------------------- |
| `to($topic)`                                     | Without it, the `default` topic. A new topic appears without channels. |
| `message($text)`                                 | The text under the title.                                              |
| `info()`, `success()`, `warning()`, `critical()` | The level, which sets the color and the urgency.                       |
| `level($level)`                                  | The level from a value.                                                |
| `url($url)`                                      | Where the title links to.                                              |
| `field($name, $value, inline: false)`            | Repeatable, at most 20.                                                |
| `table($headers, $rows, title: null)`            | Repeatable, at most 3, with 8 columns and 20 rows.                     |
| `link($label, $url, primary:, danger:)`          | Repeatable, at most 5.                                                 |
| `dedupe($key, minutes: 10)`                      | A repeat within the minutes is listed in Lumberjack, but not sent.     |

The level methods take a condition, and the last true one wins:

```php
Lumberjack::alert('Import done')
    ->success()
    ->warning($failed > 0)
    ->critical($failed > 100)
    ->send();
```

The builder is `Conditionable`, so `->when($failed > 0)->field('Failed', (string) $failed)`
works too.

A server too old for custom alerts drops them, and the project gets an
`UnsupportedFeature` issue saying to update it.
