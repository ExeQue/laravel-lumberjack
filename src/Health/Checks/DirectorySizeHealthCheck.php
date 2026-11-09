<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Checks;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;
use ExeQue\Lumberjack\Support\DirectorySize;
use Illuminate\Support\Facades\Storage;

/**
 * How much a folder on a local disk holds, which the disk check does not say: that one
 * reports how full the volume underneath is.
 */
final class DirectorySizeHealthCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly string $disk,
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $downAfter = 5,
        private readonly string $path = '',
        private readonly ?int $warnAtBytes = null,
        private readonly ?int $failAtBytes = null,
        private readonly int $timeout = 10,
        private readonly ?string $slug = null,
    ) {
    }

    public function run(): CheckResult
    {
        if (config("filesystems.disks.{$this->disk}.driver") !== 'local') {
            return CheckResult::failed("Disk [{$this->disk}] is not a configured local disk.");
        }

        $bytes = DirectorySize::of(Storage::disk($this->disk)->path($this->path), max(1, $this->timeout - 1));

        if ($bytes === null) {
            return CheckResult::failed('The folder could not be measured in time.');
        }

        $gigabytes = number_format($bytes / (1024 ** 3), 2);

        return CheckResult::threshold($bytes, $this->warnAtBytes, $this->failAtBytes, "{$gigabytes} GB", ['bytes' => $bytes]);
    }

    protected function defaultSlug(): string
    {
        return 'directory-size:'.$this->disk.($this->path !== '' ? ':'.$this->path : '');
    }
}
