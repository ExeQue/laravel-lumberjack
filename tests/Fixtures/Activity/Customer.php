<?php

declare(strict_types=1);

namespace Tests\Fixtures\Activity;

use Illuminate\Database\Eloquent\Model;

/**
 * A model with a key and personal data that must never be sent.
 */
class Customer extends Model
{
    protected $guarded = [];

    public static function make(int $id, string $email = 'jane@example.test'): self
    {
        $customer = new self(['email' => $email]);
        $customer->setAttribute('id', $id);

        return $customer;
    }
}
