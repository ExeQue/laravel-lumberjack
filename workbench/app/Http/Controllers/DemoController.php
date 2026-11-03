<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use ExeQue\Lumberjack\Facades\Lumberjack;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use LogicException;
use RuntimeException;
use Workbench\App\Jobs\SendInvoice;
use Workbench\App\Notifications\OrderShipped;
use Workbench\App\Providers\WorkbenchServiceProvider;

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

    /**
     * A checkout that touches the cache, calls an outside API and sends mail and a
     * notification: one trace with every kind of span on it.
     */
    public function spans(): RedirectResponse
    {
        $rates = Cache::remember('exchange-rates:dkk', 60, fn () => ['eur' => 0.134]);
        Cache::get('cart:'.random_int(1, 999));

        Http::timeout(5)->get('https://httpbin.org/status/'.(random_int(0, 3) === 0 ? 503 : 200), ['currency' => 'dkk', 'api_key' => 'never-recorded']);

        Mail::raw('Your order is on its way.', fn ($message) => $message->to('customer@example.test')->subject('Order shipped'));
        Notification::route('mail', 'ops@example.test')->notify(new OrderShipped());

        DB::select('select ? as rate', [$rates['eur']]);

        return $this->done('A checkout with cache, an API call, mail and a notification.');
    }

    /**
     * The same error for one of 20 signed-in customers, so the issue counts users.
     */
    public function exceptionAsUser(): never
    {
        Auth::setUser(new GenericUser(['id' => random_int(1, 20)]));

        throw new RuntimeException('Voucher could not be applied to the basket.');
    }

    /**
     * 30 of one error at once: far more than usual, so the issue escalates.
     */
    public function burst(): RedirectResponse
    {
        foreach (range(1, 30) as $attempt) {
            report(new RuntimeException('Stock service refused the reservation.'));
        }

        return $this->done('30 of the same error in one request, enough to escalate.');
    }

    /**
     * A 4xx or 5xx answer for the status codes and Apdex on the Routes page.
     */
    public function status(int $code): never
    {
        abort(in_array($code, [404, 422, 429, 503], true) ? $code : 500);
    }

    public function command(): RedirectResponse
    {
        Artisan::call('demo:report');

        return $this->done('Ran php artisan demo:report.');
    }

    /**
     * A new release, as a deploy would set it; requests and errors from now on carry it.
     */
    public function release(): RedirectResponse
    {
        $release = 'v1.'.random_int(10, 99).'.'.random_int(0, 9);
        file_put_contents(WorkbenchServiceProvider::path('storage/release'), $release);

        return $this->done("Deployed {$release}. Restart the queue worker and agent to have them report it too.");
    }

    public function breakHealth(): RedirectResponse
    {
        touch(WorkbenchServiceProvider::path('storage/payments-down'));

        return $this->done('The payments-api health check now fails; it degrades after 2 runs and goes down after 5.');
    }

    public function fixHealth(): RedirectResponse
    {
        @unlink(WorkbenchServiceProvider::path('storage/payments-down'));

        return $this->done('The payments-api health check passes again; it is recovering until 3 runs pass.');
    }

    public function breakRustfs(): RedirectResponse
    {
        touch(WorkbenchServiceProvider::path('storage/rustfs-down'));

        return $this->done('disk:rustfs now writes to a bucket that does not exist; it degrades after 2 runs and goes down after 5.');
    }

    public function fixRustfs(): RedirectResponse
    {
        @unlink(WorkbenchServiceProvider::path('storage/rustfs-down'));

        return $this->done('disk:rustfs writes to its bucket again; it is recovering until 5 runs pass.');
    }

    /**
     * Fires a registered check now instead of waiting for the scheduler.
     */
    public function runHealth(): RedirectResponse
    {
        $result = Lumberjack::health('payments-api')->run();

        return $this->done($result === null
            ? 'payments-api already ran in the last 30 seconds.'
            : "Ran payments-api now: {$result->status->value}.");
    }

    /**
     * What an app does when a read it needed just failed.
     */
    public function reportHealth(): RedirectResponse
    {
        $sent = Lumberjack::health('disk:local')->failed('Reading invoices/2026-11.pdf failed');

        return $this->done($sent ? 'Reported a failed read on disk:local.' : 'disk:local was already reported in the last 30 seconds.');
    }

    /**
     * 1200 jobs on a queue no worker listens to: a backlog that keeps growing.
     */
    public function floodQueue(): RedirectResponse
    {
        Queue::bulk(array_map(fn (int $id): SendInvoice => new SendInvoice($id), range(1, 1200)), '', 'bulk');

        return $this->done('1200 jobs on the bulk queue, which no worker runs. A backlog alert follows after 5 minutes.');
    }

    public function drainQueue(): RedirectResponse
    {
        $deleted = DB::table('jobs')->where('queue', 'bulk')->delete();

        return $this->done("Removed {$deleted} jobs from the bulk queue.");
    }

    private function done(string $status): RedirectResponse
    {
        return redirect('/')->with('status', $status);
    }
}
