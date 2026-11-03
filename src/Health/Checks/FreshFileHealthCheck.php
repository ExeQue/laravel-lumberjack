<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Checks;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;
use Illuminate\Support\Facades\Storage;

/**
 * The newest file under a path is younger than maxAgeMinutes, e.g. the last backup.
 */
final class FreshFileHealthCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly string $disk,
        private readonly string $path,
        private readonly int $maxAgeMinutes,
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $downAfter = 5,
        private readonly bool $recursive = false,
        private readonly int $timeout = 10,
        private readonly ?string $slug = null,
    ) {
    }

    public function run(): CheckResult
    {
        if (! is_array(config("filesystems.disks.{$this->disk}"))) {
            return CheckResult::failed("Disk [{$this->disk}] is not configured.");
        }

        $filesystem = Storage::disk($this->disk);
        $newest = null;

        foreach ($filesystem->files($this->path, $this->recursive) as $file) {
            $newest = max($newest ?? 0, $filesystem->lastModified($file));
        }

        if ($newest === null) {
            return CheckResult::failed("No files in [{$this->path}].");
        }

        $age = (int) floor((time() - $newest) / 60);
        $metrics = ['age_minutes' => $age];

        return $age >= $this->maxAgeMinutes
            ? CheckResult::failed("Newest file is {$age} minutes old.", $metrics)
            : CheckResult::ok("Newest file is {$age} minutes old.", $metrics);
    }

    protected function defaultSlug(): string
    {
        return "fresh:{$this->disk}:{$this->path}";
    }
}
