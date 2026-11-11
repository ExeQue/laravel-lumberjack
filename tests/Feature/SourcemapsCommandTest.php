<?php

declare(strict_types=1);

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
        Http::fake(['https://lumberjack.test/api/ingest/sourcemaps' => Http::response(status: 201)]);

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
        Http::fake(fn (Request $request) => Http::response(status: str_contains((string) $request->body(), 'vendor-77c1') ? 422 : 201));

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

        expect($tries)->toBe(6);
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
        Http::fake(['*' => Http::sequence()->push(status: 429, headers: ['Retry-After' => '7'])->whenEmpty(Http::response(status: 201))]);

        $this->artisan('lumberjack:sourcemaps')->assertExitCode(0);

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::for(7000)->milliseconds()]);
    });

    it('skips a symlinked map that points outside the public directory', function () {
        $secret = base_path('secret.map');
        File::put($secret, 'secret');
        symlink($secret, $this->assets.'/evil.js.map');
        Http::fake(['*' => Http::response(status: 201)]);

        try {
            $this->artisan('lumberjack:sourcemaps')
                ->expectsOutputToContain('not inside the public directory')
                ->assertExitCode(1);
        } finally {
            File::delete($secret);
        }

        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request->body(), 'secret'));
    });

    it('goes on with the next map when an upload throws', function () {
        Http::fake(fn (Request $request) => str_contains((string) $request->body(), 'app-3f9a') ? throw new RuntimeException('bad url') : Http::response(status: 201));

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

        Http::assertNothingSent();
    });
});
