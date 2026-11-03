<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Support\ExceptionPresenter;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\TestJob;

describe('a long-running worker', function () {
    it('does not grow while it records thousands of jobs with queries, logs and failures', function () {
        $work = function (int $runs): void {
            foreach (range(1, $runs) as $i) {
                // Every job its own id, as in a real worker.
                $job = new TestJob((string) Str::uuid());
                event(new JobProcessing('redis', $job));
                DB::select('select ?', [$i]);
                Log::info('Invoice sent', ['order' => $i]);

                $i % 10 === 0
                    ? event(new JobFailed('redis', $job, new RuntimeException("SMTP down {$i}")))
                    : event(new JobProcessed('redis', $job));

                // What the agent received is not the package's memory.
                $this->agent->payloads = [];
            }
        };

        $work(500);
        gc_collect_cycles();
        $before = memory_get_usage();

        $work(3000);
        gc_collect_cycles();

        // About 100 KB is the allocator settling; a leak of one small entry per job
        // already passes 400 KB over these 3000 jobs.
        expect(memory_get_usage() - $before)->toBeLessThan(256 * 1024);
    });

    it('forgets the source files of one exception before the next', function () {
        $dir = sys_get_temp_dir().'/lumberjack-memory-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $presenter = new ExceptionPresenter($dir);

        foreach (range(1, 40) as $i) {
            file_put_contents("{$dir}/thrower{$i}.php", "<?php\nreturn new RuntimeException('from file {$i}');\n");
            $presenter->present(require "{$dir}/thrower{$i}.php");
        }

        $files = (new ReflectionProperty($presenter, 'files'))->getValue($presenter);
        array_map('unlink', glob("{$dir}/*.php") ?: []);
        rmdir($dir);

        // Only the last exception's files, never all 40.
        expect(count($files))->toBeLessThanOrEqual(5);
    });
});
