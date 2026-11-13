<?php

declare(strict_types=1);

namespace Tests\Fixtures\Activity;

/**
 * An order as an event carries it: plain properties and a customer model.
 */
final class Order
{
    /**
     * @param  list<string>  $items
     */
    public function __construct(
        public string $uuid,
        public int $total,
        public Customer $customer,
        public array $items = [],
    ) {
    }
}
