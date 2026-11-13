<?php

declare(strict_types=1);

namespace Tests\Fixtures\Activity;

use ExeQue\Lumberjack\Attributes\RecordsLumberjackActivity;

#[RecordsLumberjackActivity(
    'order.placed',
    group: 'billing',
    actor: '@order.customer',
    subject: 'order:@order.uuid',
    payload: [
        'order' => '@order.uuid',
        'total' => '@order.total',
        'items' => '@order.items',
        'channel' => 'web',
        'handle' => '@@acme',
        'shipping' => ['express' => '@express', 'missing' => '@order.nope'],
        'customer' => '@order.customer',
    ],
)]
final class OrderPlaced
{
    public function __construct(
        public Order $order,
        public bool $express = false,
    ) {
    }
}
