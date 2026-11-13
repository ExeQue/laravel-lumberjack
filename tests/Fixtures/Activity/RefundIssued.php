<?php

declare(strict_types=1);

namespace Tests\Fixtures\Activity;

use ExeQue\Lumberjack\Attributes\RecordsLumberjackActivity;
use ExeQue\Lumberjack\Contracts\TracksLumberjackActivity;
use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\PendingActivity;

/**
 * Both a contract and an attribute: the contract wins.
 */
#[RecordsLumberjackActivity('from.attribute')]
final class RefundIssued implements TracksLumberjackActivity
{
    public function __construct(
        public Order $order,
        public int $amount,
    ) {
    }

    public function toLumberjackActivity(): ?PendingActivity
    {
        if ($this->amount === 0) {
            return null;
        }

        return Lumberjack::activity('order.refunded')
            ->group('billing')
            ->by($this->order->customer)
            ->on('order', $this->order->uuid)
            ->with(['amount' => $this->amount]);
    }
}
