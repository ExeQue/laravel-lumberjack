<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Workbench\App\Jobs\SendInvoice;

/**
 * Each action makes the SDK record something, then goes back to the demo page.
 */
class DemoController
{
    public function exception(): never
    {
        throw new LogicException('Cart total is negative after applying the discount.');
    }

    public function queries(): RedirectResponse
    {
        DB::statement('create table if not exists orders (id integer primary key, total integer)');

        foreach (range(1, 20) as $id) {
            DB::select('select * from orders where id = ?', [$id]);
        }

        return $this->done('20 queries in one request, an N+1 in the making.');
    }

    public function slow(): RedirectResponse
    {
        usleep(random_int(400_000, 1_200_000));

        return $this->done('A slow request.');
    }

    public function log(): RedirectResponse
    {
        Log::info('A customer opened the demo.');
        Log::channel('payments')->warning('Refund took {seconds} seconds', ['seconds' => 12]);
        Log::channel('payments')->error('Payment provider timed out');

        return $this->done('Three log lines, one of them an error.');
    }

    public function job(): RedirectResponse
    {
        SendInvoice::dispatch(random_int(1000, 9999));

        return $this->done('A job was dispatched.');
    }

    public function failingJob(): RedirectResponse
    {
        SendInvoice::dispatch(random_int(1000, 9999), fail: true);

        return $this->done('A failing job was dispatched.');
    }

    private function done(string $status): RedirectResponse
    {
        return redirect('/')->with('status', $status);
    }
}
