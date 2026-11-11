<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * Uploads a build's source maps to Lumberjack, so browser errors from minified bundles
 * show the original code. For a deploy script, after vite build. Straight to Lumberjack,
 * not through the agent, which the build machine may not run.
 */
class SourcemapsCommand extends Command
{
    public const string PATH = 'public/build/assets';

    /** Tries per map while Lumberjack cannot be reached; an answer, even a refusal, is final. */
    public const int TRIES = 3;

    public const int RETRY_MS = 1000;

    public const int TIMEOUT = 30;

    protected $name = 'lumberjack:sourcemaps';

    protected $description = 'Upload the source maps of a build to Lumberjack';

    public function handle(Http $http): int
    {
        if (! (bool) config('lumberjack.enabled', true)) {
            $this->components->info('Lumberjack is disabled (LUMBERJACK_ENABLED=false); no maps uploaded.');

            return self::SUCCESS;
        }

        $url = (string) config('lumberjack.url');
        $token = (string) config('lumberjack.token');

        if ($url === '' || $token === '') {
            $this->components->error('Set LUMBERJACK_URL and LUMBERJACK_TOKEN first.');

            return self::FAILURE;
        }

        $option = $this->option('path') ?: self::PATH;
        $dir = realpath(str_starts_with($option, '/') ? $option : base_path($option));
        $public = realpath(public_path());

        if ($dir === false || ! is_dir($dir)) {
            $this->components->error("No directory {$option}.");

            return self::FAILURE;
        }

        // The server needs the URL path each map's bundle is served at.
        if ($public === false || ! str_starts_with($dir.'/', $public.'/')) {
            $this->components->error("{$option} is not inside the public directory.");

            return self::FAILURE;
        }

        $maps = glob($dir.'/*.map') ?: [];

        if ($maps === []) {
            $this->components->warn("No source maps in {$option}.");

            return self::SUCCESS;
        }

        $release = $this->laravel->make('lumberjack.release');
        $failed = false;

        foreach ($maps as $map) {
            // public/build/assets/app-3f9a.js.map belongs to /build/assets/app-3f9a.js.
            $path = substr($map, strlen($public), -strlen('.map'));
            $error = $this->upload($http, $url, $token, $map, $path, is_string($release) ? $release : null);

            if ($error !== null) {
                $failed = true;
                $this->components->twoColumnDetail($path, "<fg=red>{$error}</>");

                continue;
            }

            if ($this->option('delete')) {
                @unlink($map);
            }

            $this->components->twoColumnDetail($path, '<fg=green>uploaded'.($this->option('delete') ? ', deleted' : '').'</>');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return string|null why it failed; null when uploaded
     */
    private function upload(Http $http, string $url, string $token, string $map, string $path, ?string $release): ?string
    {
        $contents = @file_get_contents($map);

        if ($contents === false) {
            return 'could not be read';
        }

        try {
            $response = $http->withToken($token)->timeout(self::TIMEOUT)
                ->retry(self::TRIES, self::RETRY_MS, fn (Throwable $e): bool => $e instanceof ConnectionException, throw: false)
                ->attach('file', $contents, basename($map))
                ->post($url.'/api/ingest/sourcemaps', array_filter(['path' => $path, 'release' => $release]));
        } catch (ConnectionException) {
            return 'Lumberjack could not be reached';
        }

        return $response->successful() ? null : "refused (HTTP {$response->status()})";
    }

    /**
     * @return list<InputOption>
     */
    protected function getOptions(): array
    {
        return [
            new InputOption('path', null, InputOption::VALUE_REQUIRED, 'The directory with the maps, inside the public directory', self::PATH),
            new InputOption('delete', null, InputOption::VALUE_NONE, 'Delete each map once it is uploaded, so it is not served publicly'),
        ];
    }
}
