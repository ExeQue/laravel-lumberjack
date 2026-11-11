<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
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

    /** Tries per map while Lumberjack cannot be reached or asks to slow down; any other answer is final. */
    public const int TRIES = 3;

    public const int RETRY_MS = 1000;

    /** The longest Retry-After waited for; Lumberjack's limit is per minute. */
    public const int MAX_RETRY_AFTER = 60;

    /** Seconds an upload may take at least; a large map gets more, at UPLOAD_BYTES_PER_SECOND. */
    public const int TIMEOUT = 30;

    /** A slow uplink's speed, so a 20 MB map is not cut off before it is sent. */
    public const int UPLOAD_BYTES_PER_SECOND = 256 * 1024;

    public const int CONNECT_TIMEOUT = 5;

    /** The largest map Lumberjack takes. */
    public const int MAX_BYTES = 20 * 1024 * 1024;

    private const string UNREACHABLE = 'Lumberjack could not be reached or timed out';

    protected $name = 'lumberjack:sourcemaps';

    protected $description = 'Upload the source maps of a build to Lumberjack';

    public function handle(Http $http): int
    {
        if (! (bool) config('lumberjack.enabled', true)) {
            // With --delete the deploy expects the maps gone; they are not, so it should know.
            if ($this->option('delete')) {
                $this->components->error('Lumberjack is disabled (LUMBERJACK_ENABLED=false); no maps uploaded, none deleted.');

                return self::FAILURE;
            }

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
        $dir = rtrim(preg_match('~^([a-z]:)?[/\\\\]~i', $option) === 1 ? $option : base_path($option), '/\\');
        $real = realpath($dir);

        if ($dir === '' || $real === false || ! is_dir($real)) {
            $this->components->error("No directory {$option}.");

            return self::FAILURE;
        }

        // The server needs the URL path each map's bundle is served at.
        $prefix = $this->publicPath($dir, $real);

        if ($prefix === null) {
            $this->components->error("{$option} is not inside the public directory.");

            return self::FAILURE;
        }

        // Listed rather than globbed: a directory named like app[2] is no pattern.
        $maps = collect(scandir($real) ?: [])
            ->filter(fn (string $file): bool => str_ends_with($file, '.map') && is_file($real.DIRECTORY_SEPARATOR.$file))
            ->sort()
            ->map(fn (string $file): string => $real.DIRECTORY_SEPARATOR.$file)
            ->values()
            ->all();

        if ($maps === []) {
            $this->components->warn("No source maps in {$option}.");

            return self::SUCCESS;
        }

        $release = $this->laravel->make('lumberjack.release');
        $failed = false;
        $unreachable = false;

        foreach ($maps as $map) {
            // public/build/assets/app-3f9a.js.map belongs to /build/assets/app-3f9a.js. Encoded as a browser
            // encodes a path, only where a URL needs it (space, #, ?, non-ASCII); Lumberjack decodes both.
            $path = (string) preg_replace_callback('~[\x00-\x20"#<>?`{}\x7F-\xFF]~', fn (array $char): string => rawurlencode($char[0]), $prefix.'/'.basename($map, '.map'));
            $mapReal = realpath($map);

            // A symlinked map could point anywhere; only what the directory holds is uploaded.
            // Once Lumberjack cannot be reached, the rest is not tried: a deploy waits on each.
            $error = match (true) {
                $unreachable => self::UNREACHABLE.', skipped',
                $mapReal === false || ! $this->inside($mapReal, $real) => 'not inside the directory',
                (int) filesize($map) > self::MAX_BYTES => 'larger than Lumberjack takes (20 MB)',
                default => $this->upload($http, $url, $token, $map, $path, is_string($release) ? $release : null),
            };
            $unreachable = $unreachable || $error === self::UNREACHABLE;

            if ($error === null && $this->option('delete') && ! @unlink($map)) {
                $error = 'uploaded, but could not be deleted';
            }

            if ($error !== null) {
                $failed = true;
                $this->components->twoColumnDetail($path, "<fg=red>{$error}</>");

                continue;
            }

            $this->components->twoColumnDetail($path, '<fg=green>uploaded'.($this->option('delete') ? ', deleted' : '').'</>');
        }

        // --no-fail: a Lumberjack that is down never stops the deploy it runs in.
        return $failed && ! $this->option('no-fail') ? self::FAILURE : self::SUCCESS;
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
            // A redirect is reported, not followed: it would turn the upload into an empty GET.
            $response = $http->withToken($token)->timeout(max(self::TIMEOUT, intdiv(strlen($contents), self::UPLOAD_BYTES_PER_SECOND)))->connectTimeout(self::CONNECT_TIMEOUT)->withoutRedirecting()
                ->retry(self::TRIES, $this->retryDelay(...), fn (?Throwable $e): bool => $e instanceof ConnectionException || $this->tooManyRequests($e), throw: false)
                ->attach('file', $contents, basename($map))
                ->post($url.'/api/ingest/sourcemaps', array_filter(['path' => $path, 'release' => $release]));
        } catch (ConnectionException) {
            return self::UNREACHABLE;
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        if (! $response->successful()) {
            return "refused (HTTP {$response->status()})";
        }

        // Lumberjack answers 201 with the map's path; any other 2xx is another app at that URL.
        return $response->status() === 201 && is_string($response->json('path')) ? null : 'not answered by Lumberjack (check LUMBERJACK_URL)';
    }

    /**
     * The directory's URL path under public, as the bundles are served; null when it is not
     * inside public. Checked as written first, so a build directory symlinked elsewhere (shared
     * between releases) still counts, then resolved, for an absolute --path to a release.
     */
    private function publicPath(string $dir, string $real): ?string
    {
        $public = rtrim(public_path(), '/\\');
        $publicReal = realpath($public);

        $relative = match (true) {
            // As written only when plain: . and .. segments or a doubled separator would end up in the URL path.
            preg_match('~(^|[/\\\\])\.{1,2}([/\\\\]|$)|[/\\\\]{2}~', $dir) !== 1 && $this->inside($dir, $public) => substr($dir, strlen($public)),
            $publicReal !== false && $this->inside($real, $publicReal) => substr($real, strlen($publicReal)),
            default => null,
        };

        return $relative === null ? null : str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    private function inside(string $path, string $public): bool
    {
        return str_starts_with($path.DIRECTORY_SEPARATOR, $public.DIRECTORY_SEPARATOR);
    }

    private function tooManyRequests(?Throwable $e): bool
    {
        return $e instanceof RequestException && $e->response->status() === 429;
    }

    /**
     * Waits as long as Lumberjack's Retry-After asks, within reason.
     */
    private function retryDelay(int $attempt, ?Throwable $e): int
    {
        $after = $e instanceof RequestException && $e->response->status() === 429 ? $e->response->header('Retry-After') : '';

        return is_numeric($after) ? min(max(0, (int) $after), self::MAX_RETRY_AFTER) * 1000 : self::RETRY_MS;
    }

    /**
     * @return list<InputOption>
     */
    protected function getOptions(): array
    {
        return [
            new InputOption('path', null, InputOption::VALUE_REQUIRED, 'The directory with the maps, inside the public directory', self::PATH),
            new InputOption('delete', null, InputOption::VALUE_NONE, 'Delete each map once it is uploaded, so it is not served publicly'),
            new InputOption('no-fail', null, InputOption::VALUE_NONE, 'Exit with 0 when uploads failed, so a deploy goes on'),
        ];
    }
}
