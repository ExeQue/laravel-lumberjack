<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SendInvoice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $orderId,
        public readonly bool $fail = false,
    ) {
    }

    public function handle(): void
    {
        DB::select('select ? as order_id', [$this->orderId]);
        Log::channel('payments')->info('Invoice sent for order {order}', ['order' => $this->orderId]);

        if ($this->fail) {
            throw new RuntimeException("SMTP server refused the invoice for order {$this->orderId}.");
        }
    }
}
