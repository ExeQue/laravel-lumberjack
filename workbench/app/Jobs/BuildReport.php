<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * A slow job for the reports queue, which Horizon works with 1 to 6 processes: enough of
 * them at once and it scales up, and back down once the queue is empty.
 */
class BuildReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $reportId)
    {
    }

    public function handle(): void
    {
        DB::select('select ? as report_id', [$this->reportId]);
        usleep(random_int(1_500_000, 3_500_000));
    }
}
