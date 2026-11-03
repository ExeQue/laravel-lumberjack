<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Install\AgentSnippets;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/lumberjack-install-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->base.'/resources/views/layouts');
    File::ensureDirectoryExists($this->base.'/bootstrap');
    File::put($this->base.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    File::put($this->base.'/.env', "APP_NAME=Demo\nAPP_ENV=local\n");
    File::put($this->base.'/.env.example', "APP_NAME=\n");
    File::put($this->base.'/bootstrap/providers.php', "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n");
    File::put($this->base.'/resources/views/layouts/app.blade.php', "<html>\n<head>\n    <title>App</title>\n</head>\n<body></body>\n</html>\n");

    $this->app->setBasePath($this->base);
    config(['lumberjack.url' => '', 'lumberjack.token' => null, 'lumberjack.public_key' => null]);

    $this->install = fn (array $options = []) => $this->artisan('lumberjack:install', [
        '--no-interaction' => true,
        '--url' => 'https://lumberjack.example.com',
        '--token' => 'lj_srv_secret',
        '--key' => 'pk_public',
        '--no-verify' => true,
        ...$options,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->base);
});

function installed(string $path): string
{
    return (string) file_get_contents(test()->base.'/'.$path);
}

describe('lumberjack:install', function () {
    it('writes the keys to .env and .env.example, keeping the rest of the files as they were', function () {
        ($this->install)()->assertSuccessful();

        expect(installed('.env'))->toBe("APP_NAME=Demo\nAPP_ENV=local\n\nLUMBERJACK_URL=https://lumberjack.example.com\nLUMBERJACK_TOKEN=lj_srv_secret\nLUMBERJACK_PUBLIC_KEY=pk_public\n")
            ->and(installed('.env.example'))->toBe("APP_NAME=\n\nLUMBERJACK_URL=\nLUMBERJACK_TOKEN=\nLUMBERJACK_PUBLIC_KEY=\n");
    });

    it('keeps an existing value without --force, and replaces it with --force', function () {
        File::put($this->base.'/.env', "LUMBERJACK_URL=https://old.example.com\nAPP_KEY=x\n");

        ($this->install)()->assertSuccessful();
        expect(installed('.env'))->toStartWith("LUMBERJACK_URL=https://old.example.com\nLUMBERJACK_TOKEN=lj_srv_secret\n");

        ($this->install)(['--force' => true])->assertSuccessful();
        expect(installed('.env'))->toStartWith("LUMBERJACK_URL=https://lumberjack.example.com\nLUMBERJACK_TOKEN=lj_srv_secret\n")
            ->and(installed('.env'))->toContain("APP_KEY=x\n");
    });

    it('asks before replacing an existing value', function () {
        File::put($this->base.'/.env', "LUMBERJACK_URL=https://old.example.com\n");

        $this->artisan('lumberjack:install', ['--no-verify' => true, '--checks' => 'database', '--queues' => ''])
            ->expectsQuestion('Lumberjack URL', 'https://lumberjack.example.com')
            ->expectsQuestion('Server token', 'lj_srv_secret')
            ->expectsQuestion('Public key, for browser errors', '')
            ->expectsConfirmation('LUMBERJACK_URL is already set to "https://old.example.com". Replace it?', 'yes')
            ->expectsConfirmation('Add the keys to .env.example, with empty values?', 'no')
            ->expectsChoice('Put @lumberjack in the <head> of which layouts?', ['layouts/app.blade.php'], ['layouts/app.blade.php', 'layouts/app.blade.php'])
            ->expectsChoice('How does this app run in production? The agent runs next to it, one per server.', 'later', [...array_keys(AgentSnippets::PLATFORMS), ...array_values(AgentSnippets::PLATFORMS)])
            ->assertSuccessful();

        expect(installed('.env'))->toStartWith("LUMBERJACK_URL=https://lumberjack.example.com\n")
            ->and(installed('.env'))->not->toContain('LUMBERJACK_PUBLIC_KEY')
            ->and(installed('.env.example'))->toBe("APP_NAME=\n")
            ->and(installed('resources/views/layouts/app.blade.php'))->toContain("    @lumberjack\n</head>");
    });

    it('changes nothing when run again', function () {
        $options = ['--checks' => 'database', '--layout' => ['resources/views/layouts/app.blade.php']];
        ($this->install)($options)->assertSuccessful();
        $files = collect(['.env', '.env.example', 'bootstrap/providers.php', 'app/Providers/LumberjackServiceProvider.php', 'resources/views/layouts/app.blade.php'])->mapWithKeys(fn (string $path) => [$path => installed($path)]);

        ($this->install)($options)->assertSuccessful();

        expect($files->map(fn (string $contents, string $path) => installed($path) === $contents)->all())->each->toBeTrue();
    });

    it('puts @lumberjack in the layout once, before </head>', function () {
        ($this->install)(['--layout' => ['resources/views/layouts/app.blade.php']])->assertSuccessful();
        ($this->install)(['--layout' => ['resources/views/layouts/app.blade.php']])->assertSuccessful();

        expect(installed('resources/views/layouts/app.blade.php'))->toBe("<html>\n<head>\n    <title>App</title>\n    @lumberjack\n</head>\n<body></body>\n</html>\n");
    });

    it('leaves the layouts alone without --layout when not interactive', function () {
        ($this->install)()->assertSuccessful();

        expect(installed('resources/views/layouts/app.blade.php'))->not->toContain('@lumberjack');
    });

    it('puts @lumberjack in the only layout with --layout=auto', function () {
        ($this->install)(['--layout' => ['auto']])->assertSuccessful();

        expect(installed('resources/views/layouts/app.blade.php'))->toContain("    @lumberjack\n</head>");
    });

    it('stops before writing anything when --layout=auto finds several layouts', function () {
        File::put($this->base.'/resources/views/admin.blade.php', "<html><head></head></html>\n");

        ($this->install)(['--layout' => ['auto']])
            ->expectsOutputToContain('More than one layout')
            ->assertFailed();

        expect(installed('.env'))->not->toContain('LUMBERJACK_URL');
    });

    it('generates the provider with the chosen checks and registers it once', function () {
        ($this->install)(['--checks' => 'database,cache,disk:s3,queue:redis:emails,failed-jobs'])->assertSuccessful();
        ($this->install)(['--checks' => 'database,cache,disk:s3,queue:redis:emails,failed-jobs'])->assertSuccessful();

        $provider = installed('app/Providers/LumberjackServiceProvider.php');

        expect($provider)->toContain("namespace App\\Providers;\n")
            ->toContain("        Lumberjack::healthChecks()\n            ->database()\n            ->cache()\n            ->disk('s3')\n            ->queue('redis', queue: 'emails')\n            ->failedJobs();\n")
            ->and(substr_count(installed('bootstrap/providers.php'), 'App\\Providers\\LumberjackServiceProvider::class'))->toBe(1)
            ->and(installed('bootstrap/providers.php'))->toContain('App\\Providers\\AppServiceProvider::class');

        // The generated file is valid PHP.
        exec('php -l '.escapeshellarg($this->base.'/app/Providers/LumberjackServiceProvider.php'), result_code: $code);
        expect($code)->toBe(0);
    });

    it('suggests checks from the app\'s config when none are given', function () {
        config([
            'filesystems.disks.s3' => ['driver' => 's3', 'bucket' => 'media'],
            'filesystems.disks.backups' => ['driver' => 's3', 'bucket' => ''],
            'filesystems.disks.archive' => ['driver' => 'sftp', 'host' => 'files.example.com'],
            'filesystems.disks.unused' => ['driver' => 'ftp', 'host' => ''],
            'queue.default' => 'redis',
            'cache.default' => 'redis',
        ]);

        ($this->install)()->assertSuccessful();

        expect(installed('app/Providers/LumberjackServiceProvider.php'))->toContain('->database()')
            ->toContain('->cache()')
            ->toContain('->redis()')
            ->toContain("->disk('s3')")
            ->toContain('->queue()')
            ->toContain('->debugMode()')
            ->toContain('->migrations()')
            ->toContain("->disk('archive')")
            ->not->toContain("->disk('local')")
            ->not->toContain("->disk('backups')")
            ->not->toContain("->disk('unused')");
    });

    it('keeps an edited provider without --force', function () {
        File::ensureDirectoryExists($this->base.'/app/Providers');
        File::put($this->base.'/app/Providers/LumberjackServiceProvider.php', '<?php // mine');

        ($this->install)(['--checks' => 'database'])->assertSuccessful();
        expect(installed('app/Providers/LumberjackServiceProvider.php'))->toBe('<?php // mine');

        ($this->install)(['--checks' => 'database', '--force' => true])->assertSuccessful();
        expect(installed('app/Providers/LumberjackServiceProvider.php'))->toContain('->database()');
    });

    it('refuses an unknown check before writing anything', function () {
        ($this->install)(['--checks' => 'database,nope'])->assertFailed();

        expect(installed('.env'))->not->toContain('LUMBERJACK');
    });

    it('writes the queues to watch', function () {
        ($this->install)(['--queues' => 'default, redis:emails'])->assertSuccessful();

        expect(installed('.env'))->toContain("LUMBERJACK_QUEUES=default,redis:emails\n");
    });

    it('verifies the token with a heartbeat', function () {
        Http::fake(['lumberjack.example.com/api/ingest/heartbeat' => Http::response(['ok' => true])]);

        ($this->install)(['--no-verify' => false])->expectsOutputToContain('Connected to https://lumberjack.example.com.')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer lj_srv_secret') && $request['name'] === 'lumberjack:install');
    });

    it('stops without changes when Lumberjack refuses the token', function () {
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        ($this->install)(['--no-verify' => false])->expectsOutputToContain('refused the server token (HTTP 401)')->assertFailed();

        expect(installed('.env'))->not->toContain('LUMBERJACK');
    });

    it('says when Lumberjack cannot be reached, and can continue anyway', function () {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        ($this->install)(['--no-verify' => false])->expectsOutputToContain('Cannot reach https://lumberjack.example.com: Could not resolve host')->assertFailed();

        $this->artisan('lumberjack:install', ['--url' => 'https://lumberjack.example.com', '--token' => 'lj_srv_secret', '--checks' => 'database', '--queues' => ''])
            ->expectsQuestion('Lumberjack URL', 'https://lumberjack.example.com')
            ->expectsQuestion('Server token', '')
            ->expectsQuestion('Public key, for browser errors', '')
            ->expectsChoice('What now?', 'continue', ['retry', 'continue', 'stop', 'Enter the values again', 'Continue anyway', 'Stop, change nothing'])
            ->expectsConfirmation('Add the keys to .env.example, with empty values?', 'yes')
            ->expectsChoice('Put @lumberjack in the <head> of which layouts?', [], ['layouts/app.blade.php', 'layouts/app.blade.php'])
            ->expectsChoice('How does this app run in production? The agent runs next to it, one per server.', 'supervisor', [...array_keys(AgentSnippets::PLATFORMS), ...array_values(AgentSnippets::PLATFORMS)])
            ->expectsOutputToContain('command=php '.$this->base.'/artisan lumberjack:agent')
            ->assertSuccessful();

        expect(installed('.env'))->toContain("LUMBERJACK_TOKEN=lj_srv_secret\n");
    });
});
