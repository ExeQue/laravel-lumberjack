<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Console\SourcemapsCommand;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    $this->assets = public_path('build/assets');
    File::ensureDirectoryExists($this->assets);
    File::put($this->assets.'/app-3f9a.js.map', '{"version":3}');
    File::put($this->assets.'/vendor-77c1.js.map', '{"version":3}');
    File::put($this->assets.'/app-3f9a.js', 'console.log(1)');
});

afterEach(function () {
    File::deleteDirectory(public_path('build'));
});

describe('lumberjack:sourcemaps', function () {
    it('uploads each map with the public path of its bundle and the release', function () {
        Http::fake(['https://lumberjack.test/api/ingest/sourcemaps' => Http::response(['path' => '/x.js'], 201)]);

        $this->artisan('lumberjack:sourcemaps')
            ->expectsOutputToContain('/build/assets/app-3f9a.js')
            ->expectsOutputToContain('/build/assets/vendor-77c1.js')
            ->assertExitCode(0);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret-token')
            && $request->isMultipart()
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'path' && $part['contents'] === '/build/assets/app-3f9a.js')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'release' && $part['contents'] === 'abc123')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'file' && $part['filename'] === 'app-3f9a.js.map'));

        expect(File::exists($this->assets.'/app-3f9a.js.map'))->toBeTrue();
    });

    it('deletes each map once uploaded with --delete, and keeps one that failed', function () {
        Http::fake(fn (Request $request) => Http::response(['path' => '/x.js'], str_contains((string) $request->body(), 'vendor-77c1') ? 422 : 201));

        $this->artisan('lumberjack:sourcemaps', ['--delete' => true])
            ->expectsOutputToContain('refused (HTTP 422)')
            ->assertExitCode(1);

        expect(File::exists($this->assets.'/app-3f9a.js.map'))->toBeFalse()
            ->and(File::exists($this->assets.'/vendor-77c1.js.map'))->toBeTrue()
            ->and(File::exists($this->assets.'/app-3f9a.js'))->toBeTrue();
    });

    it('retries only when Lumberjack cannot be reached', function () {
        $tries = 0;
        Http::fake(function () use (&$tries) {
            $tries++;

            throw new ConnectionException('Connection refused');
        });

        $this->artisan('lumberjack:sourcemaps', ['--path' => public_path('build/assets')])
            ->expectsOutputToContain('Lumberjack could not be reached')
            ->assertExitCode(1);

        // Three tries for the first map; the second is skipped, not waited on.
        expect($tries)->toBe(3);
    });

    it('does not retry a refusal', function () {
        Http::fake(['*' => Http::response(status: 401)]);

        $this->artisan('lumberjack:sourcemaps')->assertExitCode(1);

        Http::assertSentCount(2);
    });

    it('fails for a directory outside the public directory, and warns when there are no maps', function () {
        Http::fake();

        $this->artisan('lumberjack:sourcemaps', ['--path' => 'storage'])
            ->expectsOutputToContain('is not inside the public directory')
            ->assertExitCode(1);

        File::delete(File::glob($this->assets.'/*.map'));

        $this->artisan('lumberjack:sourcemaps')
            ->expectsOutputToContain('No source maps')
            ->assertExitCode(0);

        Http::assertNothingSent();
    });

    it('waits out a rate limit and tries again', function () {
        Http::fake(['*' => Http::sequence()->push(status: 429, headers: ['Retry-After' => '7'])->whenEmpty(Http::response(['path' => '/x.js'], 201))]);

        $this->artisan('lumberjack:sourcemaps')->assertExitCode(0);

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::for(7000)->milliseconds()]);
    });

    it('reports a redirect as a refusal instead of following it', function () {
        Http::fake(['*' => Http::response(status: 302, headers: ['Location' => 'https://elsewhere.test'])]);

        $this->artisan('lumberjack:sourcemaps')
            ->expectsOutputToContain('refused (HTTP 302)')
            ->assertExitCode(1);
    });

    it('keeps the maps when another app answers at the URL', function () {
        Http::fake(['*' => Http::response('<html>', 200)]);

        $this->artisan('lumberjack:sourcemaps', ['--delete' => true])
            ->expectsOutputToContain('not answered by Lumberjack')
            ->assertExitCode(1);

        expect(File::exists($this->assets.'/app-3f9a.js.map'))->toBeTrue();
    });

    it('takes a build directory symlinked from outside public, with the URL path inside it', function () {
        $shared = base_path('shared-build');
        File::ensureDirectoryExists($shared);
        File::put($shared.'/app-77.js.map', '{"version":3}');
        File::deleteDirectory(public_path('build'));
        File::ensureDirectoryExists(public_path('build'));
        symlink($shared, public_path('build/assets'));
        Http::fake(['*' => Http::response(['path' => '/build/assets/app-77.js'], 201)]);

        try {
            $this->artisan('lumberjack:sourcemaps')->assertExitCode(0);
        } finally {
            unlink(public_path('build/assets'));
            File::deleteDirectory($shared);
        }

        Http::assertSent(fn (Request $request): bool => collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'path' && $part['contents'] === '/build/assets/app-77.js'));
    });

    it('refuses a path that climbs out of public', function () {
        Http::fake();

        $this->artisan('lumberjack:sourcemaps', ['--path' => 'public/../storage'])
            ->expectsOutputToContain('is not inside the public directory')
            ->assertExitCode(1);
    });

    it('skips a symlinked map that points outside the public directory', function () {
        $secret = base_path('secret.map');
        File::put($secret, 'secret');
        symlink($secret, $this->assets.'/evil.js.map');
        Http::fake(['*' => Http::response(['path' => '/x.js'], 201)]);

        try {
            $this->artisan('lumberjack:sourcemaps')
                ->expectsOutputToContain('not inside the directory')
                ->assertExitCode(1);
        } finally {
            File::delete($secret);
        }

        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request->body(), 'secret'));
    });

    it('goes on with the next map when an upload throws', function () {
        Http::fake(fn (Request $request) => str_contains((string) $request->body(), 'app-3f9a') ? throw new RuntimeException('bad url') : Http::response(['path' => '/x.js'], 201));

        $this->artisan('lumberjack:sourcemaps')
            ->expectsOutputToContain('bad url')
            ->assertExitCode(1);

        Http::assertSentCount(1);
    });

    it('fails without a URL or token, and for a directory that does not exist', function () {
        Http::fake();
        Config::set('lumberjack.token', null);

        $this->artisan('lumberjack:sourcemaps')
            ->expectsOutputToContain('Set LUMBERJACK_URL and LUMBERJACK_TOKEN first')
            ->assertExitCode(1);

        Config::set('lumberjack.token', 'secret-token');

        $this->artisan('lumberjack:sourcemaps', ['--path' => 'public/nope'])
            ->expectsOutputToContain('No directory public/nope')
            ->assertExitCode(1);

        Http::assertNothingSent();
    });

    it('uploads nothing when Lumberjack is disabled', function () {
        Http::fake();
        Config::set('lumberjack.enabled', false);

        $this->artisan('lumberjack:sourcemaps')->assertExitCode(0);
        $this->artisan('lumberjack:sourcemaps', ['--delete' => true])
            ->expectsOutputToContain('none deleted')
            ->assertExitCode(1);

        Http::assertNothingSent();
        expect(File::exists($this->assets.'/app-3f9a.js.map'))->toBeTrue();
    });

    it('sends the plain URL path for a --path with . segments or doubled separators', function (string $path) {
        Http::fake(['*' => Http::response(['path' => '/x.js'], 201)]);

        $this->artisan('lumberjack:sourcemaps', ['--path' => $path])->assertExitCode(0);

        Http::assertSent(fn (Request $request): bool => collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'path' && $part['contents'] === '/build/assets/app-3f9a.js'));
    })->with([
        'doubled' => ['public//build/assets'],
        'dot' => ['public/./build/assets'],
        'trailing dot' => ['public/build/assets/.'],
    ]);

    it('finds the maps in a directory whose name looks like a glob pattern', function () {
        $odd = public_path('build/app[2]');
        File::ensureDirectoryExists($odd);
        File::put($odd.'/app-1.js.map', '{"version":3}');
        Http::fake(['*' => Http::response(['path' => '/x.js'], 201)]);

        $this->artisan('lumberjack:sourcemaps', ['--path' => 'public/build/app[2]'])->assertExitCode(0);

        Http::assertSentCount(1);
    });

    it('refuses the root directory as --path', function () {
        Http::fake();

        $this->artisan('lumberjack:sourcemaps', ['--path' => '/'])->assertExitCode(1);

        Http::assertNothingSent();
    });

    it('skips a map larger than Lumberjack takes without reading it', function () {
        $big = fopen($this->assets.'/huge.js.map', 'w');
        ftruncate($big, SourcemapsCommand::MAX_BYTES + 1);
        fclose($big);
        Http::fake(['*' => Http::response(['path' => '/x.js'], 201)]);

        $this->artisan('lumberjack:sourcemaps')
            ->expectsOutputToContain('larger than Lumberjack takes')
            ->assertExitCode(1);

        Http::assertSentCount(2);
    });

    it('waits at most a minute however long a rate limit asks', function () {
        Http::fake(['*' => Http::sequence()->push(status: 429, headers: ['Retry-After' => '3600'])->whenEmpty(Http::response(['path' => '/x.js'], 201))]);

        $this->artisan('lumberjack:sourcemaps')->assertExitCode(0);

        Sleep::assertSequence([Sleep::for(SourcemapsCommand::MAX_RETRY_AFTER * 1000)->milliseconds()]);
    });

    it('exits with 0 on failed uploads with --no-fail, so the deploy goes on', function () {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->artisan('lumberjack:sourcemaps', ['--no-fail' => true])
            ->expectsOutputToContain('could not be reached')
            ->assertExitCode(0);
    });

    it('encodes the URL path as a browser does, only where a URL needs it', function () {
        File::ensureDirectoryExists(public_path('build/my assets[2]'));
        File::put(public_path('build/my assets[2]/a@b(1)+é.js.map'), '{"version":3}');
        Http::fake(['*' => Http::response(['path' => '/x.js'], 201)]);

        $this->artisan('lumberjack:sourcemaps', ['--path' => 'public/build/my assets[2]'])->assertExitCode(0);

        // new URL('/build/my assets[2]/a@b(1)+é.js', location).pathname
        Http::assertSent(fn (Request $request): bool => collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'path' && $part['contents'] === '/build/my%20assets[2]/a@b(1)+%C3%A9.js'));
    });

    it('reports a rate limit that lasts all its tries as a refusal', function () {
        Http::fake(['*' => Http::response(status: 429, headers: ['Retry-After' => '-5'])]);

        $this->artisan('lumberjack:sourcemaps')
            ->expectsOutputToContain('refused (HTTP 429)')
            ->assertExitCode(1);

        Http::assertSentCount(6);
        // A negative Retry-After waits nothing, not a negative time.
        Sleep::assertSequence(array_fill(0, 4, Sleep::for(0)->milliseconds()));
    });
});
