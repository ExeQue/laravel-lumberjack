<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Contracts;

/**
 * Works out the release (e.g. the commit) when LUMBERJACK_RELEASE is empty. Asked
 * once per process.
 */
interface ReleaseResolver
{
    public function resolve(): ?string;
}
