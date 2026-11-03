<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Checks;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Writes, reads back and deletes a small file on a disk. For a local disk also
 * how full the disk under its root is.
 */
final class DiskHealthCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly string $disk,
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $downAfter = 5,
        private readonly ?int $warnAt = null,
        private readonly ?int $failAt = null,
        private readonly int $timeout = 10,
        private readonly ?string $slug = null,
    ) {
    }

    public function run(): CheckResult
    {
        $config = config("filesystems.disks.{$this->disk}");

        if (! is_array($config)) {
            return CheckResult::failed("Disk [{$this->disk}] is not configured.");
        }

        $filesystem = Storage::disk($this->disk);
        $file = '.lumberjack-health-'.Str::random(16);
        $contents = Str::random(32);
        $start = hrtime(true);

        try {
            if (! $filesystem->put($file, $contents)) {
                return CheckResult::failed("Could not write to disk [{$this->disk}].");
            }

            if ($filesystem->get($file) !== $contents) {
                return CheckResult::failed("Disk [{$this->disk}] did not read back what was written.");
            }
        } finally {
            $filesystem->delete($file);
        }

        $metrics = ['latency_ms' => self::since($start)];

        if (($config['driver'] ?? null) !== 'local') {
            return CheckResult::ok(metrics: $metrics);
        }

        $root = $filesystem->path('');
        $total = @disk_total_space($root);
        $free = @disk_free_space($root);

        if ($total === false || $free === false || $total <= 0) {
            return CheckResult::ok(metrics: $metrics);
        }

        $metrics['used_percent'] = $used = round(($total - $free) / $total * 100, 1);

        return CheckResult::threshold($used, $this->warnAt, $this->failAt, "{$used}% used", $metrics);
    }

    protected function defaultSlug(): string
    {
        return "disk:{$this->disk}";
    }
}
