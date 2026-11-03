<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Console;

use ExeQue\Lumberjack\AgentClient;
use ExeQue\Lumberjack\Install\AgentSnippets;
use ExeQue\Lumberjack\Install\EnvFile;
use ExeQue\Lumberjack\Install\HealthCheckProvider;
use ExeQue\Lumberjack\Install\LayoutInjector;
use ExeQue\Lumberjack\Support\Client;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use RuntimeException;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * Sets Lumberjack up in an app: the keys in .env, the browser script in the layout, a
 * service provider with health checks, the queues to watch and how to run the agent.
 * Running it again changes nothing that is already set up.
 */
class InstallCommand extends Command
{
    public const string DOCS = 'https://exeque.github.io/laravel-lumberjack/docs/';

    protected $name = 'lumberjack:install';

    protected $description = 'Set Lumberjack up in this app';

    private EnvFile $env;

    /** Whether .env changed, so a running agent needs a restart to see it. */
    private bool $envChanged = false;

    public function handle(Http $http, Repository $config, AgentClient $agent): int
    {
        $this->env = new EnvFile($this->laravel->environmentFilePath());

        try {
            $checks = $this->checksOption();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        // A script must not guess between several layouts; it stops before anything is written.
        if ($this->arrayOption('layout') === ['auto'] && count($this->autoLayouts()) > 1) {
            $this->components->error('More than one layout has a </head> ('.implode(', ', $this->autoLayouts()).'); pass --layout with the one to use.');

            return self::FAILURE;
        }

        $connection = $this->connection($http, $config);

        if ($connection === null) {
            return self::FAILURE;
        }

        $this->components->info('Writing .env');
        $this->setEnv('LUMBERJACK_URL', $connection['url']);
        $this->setEnv('LUMBERJACK_TOKEN', $connection['token'], secret: true);

        if ($connection['key'] !== '') {
            $this->setEnv('LUMBERJACK_PUBLIC_KEY', $connection['key']);
        }

        $this->queues($config);
        $this->envExample();
        $this->layouts();
        $this->healthChecks($config, $checks);
        $this->agent();
        $this->finish($agent);

        return self::SUCCESS;
    }

    /**
     * @return array{url: string, token: string, key: string}|null null when the user gave up
     */
    private function connection(Http $http, Repository $config): ?array
    {
        $url = $this->stringOption('url') ?? $this->configString($config, 'lumberjack.url') ?? '';
        $token = $this->stringOption('token') ?? $this->configString($config, 'lumberjack.token') ?? '';
        $key = $this->stringOption('key') ?? $this->configString($config, 'lumberjack.public_key') ?? '';

        while (true) {
            if ($this->input->isInteractive()) {
                $url = text(
                    'Lumberjack URL',
                    default: $url !== '' ? $url : 'https://',
                    required: true,
                    validate: fn (string $value): ?string => filter_var($value, FILTER_VALIDATE_URL) !== false ? null : 'Enter a URL like https://lumberjack.example.com.',
                    hint: 'The project\'s settings in Lumberjack show it under Install.',
                );
                $entered = password(
                    'Server token',
                    placeholder: $token !== '' ? 'Leave empty to keep the current token' : 'lj_srv_...',
                    required: $token === '',
                    hint: 'Only the agent uses it, to send to Lumberjack.',
                );
                $token = $entered !== '' ? $entered : $token;
                $key = text('Public key, for browser errors', default: $key, hint: 'Optional. Leave it empty to send no browser errors.');
            }

            $url = rtrim(trim($url), '/');
            $token = trim($token);
            $key = trim($key);

            if ($url === '' || $url === 'https:' || $token === '') {
                $this->components->error('Lumberjack needs a URL and a server token: pass --url and --token.');

                return null;
            }

            if ($this->option('no-verify')) {
                break;
            }

            $problem = $this->verify($http, $url, $token);

            if ($problem === null) {
                $this->components->info("Connected to {$url}.");

                break;
            }

            $this->components->error($problem);

            if (! $this->input->isInteractive()) {
                $this->components->warn('Nothing was changed. Pass --no-verify to set it up without checking.');

                return null;
            }

            $next = select('What now?', ['retry' => 'Enter the values again', 'continue' => 'Continue anyway', 'stop' => 'Stop, change nothing']);

            if ($next === 'continue') {
                break;
            }

            if ($next === 'stop') {
                return null;
            }
        }

        return ['url' => $url, 'token' => $token, 'key' => $key];
    }

    /**
     * Sends a heartbeat with the token, as the agent would; the reason when it fails.
     */
    private function verify(Http $http, string $url, string $token): ?string
    {
        try {
            $response = $http->withToken($token)->acceptJson()->connectTimeout(5)->timeout(5)->post($url.'/api/ingest/heartbeat', [
                'host' => gethostname() ?: 'localhost',
                'name' => 'lumberjack:install',
                'client' => Client::describe(),
                'version' => Client::version(),
            ]);
        } catch (ConnectionException $e) {
            return "Cannot reach {$url}: {$e->getMessage()}";
        } catch (Throwable $e) {
            return "Sending to {$url} failed: {$e->getMessage()}";
        }

        return match (true) {
            $response->successful() => null,
            $response->status() === 401 => 'Lumberjack refused the server token (HTTP 401). Copy it again from the project\'s settings, under Install.',
            default => "Lumberjack answered HTTP {$response->status()} on {$url}/api/ingest/heartbeat. Is the URL right?",
        };
    }

    /**
     * Sets a key in .env, asking first when it holds another value.
     */
    private function setEnv(string $key, string $value, bool $secret = false): void
    {
        $current = $this->env->get($key);

        if ($current === $value) {
            $this->components->twoColumnDetail($key, 'already set');

            return;
        }

        if ($current !== null && $current !== '' && ! $this->option('force')) {
            $shown = $secret ? 'another token' : "\"{$current}\"";

            if (! $this->input->isInteractive() || ! confirm("{$key} is already set to {$shown}. Replace it?", default: false)) {
                $this->components->twoColumnDetail($key, 'kept (pass --force to replace it)');

                return;
            }
        }

        $this->env->set($key, $value);
        $this->envChanged = true;
        $this->components->twoColumnDetail($key, 'set');
    }

    /**
     * The Lumberjack keys in .env, with empty values, in .env.example: only keys it lacks.
     */
    private function envExample(): void
    {
        $example = new EnvFile($this->laravel->basePath('.env.example'));
        $keys = array_filter(['LUMBERJACK_URL', 'LUMBERJACK_TOKEN', 'LUMBERJACK_PUBLIC_KEY', 'LUMBERJACK_QUEUES'], fn (string $key): bool => $this->env->get($key) !== null && $example->get($key) === null);

        if (! $example->exists() || $keys === [] || ($this->input->isInteractive() && ! confirm('Add the keys to .env.example, with empty values?'))) {
            return;
        }

        foreach ($keys as $key) {
            $example->set($key, '');
        }

        $this->components->twoColumnDetail('.env.example', implode(', ', $keys).' added');
    }

    /**
     * The layouts --layout=auto would use: those with a </head> and no @lumberjack yet.
     *
     * @return list<string>
     */
    private function autoLayouts(): array
    {
        $views = $this->laravel->resourcePath('views');

        return array_values(array_filter(LayoutInjector::layouts($views), fn (string $layout): bool => ! LayoutInjector::has($views.'/'.$layout)));
    }

    private function layouts(): void
    {
        $this->components->info('Browser errors');

        $views = $this->laravel->resourcePath('views');
        $given = $this->arrayOption('layout');

        if ($given === ['auto']) {
            $files = array_map(fn (string $layout): string => $views.'/'.$layout, $this->autoLayouts());
        } elseif ($given !== []) {
            $files = array_map(fn (string $path): string => str_starts_with($path, '/') ? $path : $this->laravel->basePath($path), $given);
        } elseif ($this->input->isInteractive()) {
            $found = LayoutInjector::layouts($views);
            $without = array_values(array_filter($found, fn (string $layout): bool => ! LayoutInjector::has($views.'/'.$layout)));

            if ($found === []) {
                $this->components->warn('No Blade layout with a </head> found. Put @lumberjack in the <head> of your layout yourself.');

                return;
            }

            if ($without === []) {
                $this->components->twoColumnDetail('@lumberjack', 'already in '.implode(', ', $found));

                return;
            }

            $picked = multiselect(
                'Put @lumberjack in the <head> of which layouts?',
                array_combine($without, $without),
                default: array_values(array_filter($without, fn (string $layout): bool => basename($layout) === 'app.blade.php' || str_starts_with($layout, 'layouts/'))),
                hint: 'It renders the browser script, which sends the page\'s errors to Lumberjack.',
            );
            $files = array_map(fn (int|string $layout): string => $views.'/'.$layout, $picked);
        } else {
            $this->components->twoColumnDetail('@lumberjack', 'skipped; pass --layout to put it in a layout');

            return;
        }

        foreach ($files as $file) {
            $shown = ltrim(substr($file, strlen($this->laravel->basePath())), '/');

            if (! is_file($file)) {
                $this->components->warn("{$shown} does not exist.");

                continue;
            }

            $this->components->twoColumnDetail($shown, match (true) {
                LayoutInjector::inject($file) => '@lumberjack added',
                LayoutInjector::has($file) => '@lumberjack already there',
                default => 'no </head> found; add @lumberjack yourself',
            });
        }
    }

    /**
     * @param  list<string>|null  $given  from --checks
     */
    private function healthChecks(Repository $config, ?array $given): void
    {
        $this->components->info('Health checks');

        $path = $this->laravel->path('Providers/LumberjackServiceProvider.php');
        $namespace = $this->appNamespace().'Providers';
        $class = $namespace.'\\LumberjackServiceProvider';
        $exists = is_file($path);

        if ($exists && $this->input->isInteractive() && ! $this->option('force') && $given === null
            && ! confirm('app/Providers/LumberjackServiceProvider.php exists. Choose its health checks again (it is rewritten)?', default: false)) {
            $this->register($class);

            return;
        }

        [$options, $suggested] = (new HealthCheckProvider($config))->options();

        $choices = $given ?? ($this->input->isInteractive()
            ? array_values(array_map(strval(...), multiselect('Which health checks should run every minute?', $options, default: $suggested, scroll: 15, hint: 'They go in app/Providers/LumberjackServiceProvider.php; add more there later.')))
            : $suggested);

        if ($choices === []) {
            $this->components->twoColumnDetail('Health checks', 'none chosen');

            return;
        }

        $contents = HealthCheckProvider::render($namespace, $choices);
        $current = $exists ? file_get_contents($path) : null;

        if ($current === $contents) {
            $this->components->twoColumnDetail('app/Providers/LumberjackServiceProvider.php', 'already up to date');
        } elseif ($exists && ! $this->option('force') && ! $this->input->isInteractive()) {
            $this->components->twoColumnDetail('app/Providers/LumberjackServiceProvider.php', 'kept (pass --force to replace it)');
        } else {
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, $contents);
            $this->components->twoColumnDetail('app/Providers/LumberjackServiceProvider.php', ($exists ? 'replaced with ' : 'created with ').implode(', ', $choices));
        }

        $this->register($class);
    }

    private function register(string $class): void
    {
        $providers = $this->laravel->bootstrapPath('providers.php');

        if (! is_file($providers)) {
            $this->components->warn("Register {$class} in the providers of config/app.php.");

            return;
        }

        if (str_contains((string) file_get_contents($providers), $class)) {
            $this->components->twoColumnDetail('bootstrap/providers.php', 'already registered');

            return;
        }

        ServiceProvider::addProviderToBootstrapFile($class, $providers);
        $this->components->twoColumnDetail('bootstrap/providers.php', 'registered');
    }

    private function queues(Repository $config): void
    {
        $default = $this->configString($config, 'queue.default') ?? 'sync';
        $suggested = $this->suggestedQueues($config, $default);
        $value = $this->stringOption('queues');
        $current = $this->env->get('LUMBERJACK_QUEUES');

        if ($value === null) {
            if ($this->input->isInteractive() && ($suggested !== '' || $current !== null)) {
                $value = text(
                    'Queues whose size the agent sends every minute',
                    default: $current ?? $suggested,
                    hint: 'Comma separated, as connection:queue or a queue on the default connection. Empty: the default queue.',
                );
            } else {
                $value = $suggested;
            }
        }

        $value = implode(',', array_filter(array_map(trim(...), explode(',', $value))));

        if ($value !== '') {
            $this->setEnv('LUMBERJACK_QUEUES', $value);
        }
    }

    /**
     * The default queue and Horizon's queues, when that is more than the default queue alone.
     */
    private function suggestedQueues(Repository $config, string $default): string
    {
        $queues = [];
        $add = function (mixed $connection, mixed $queue) use (&$queues, $default): void {
            foreach (is_array($queue) ? $queue : explode(',', (string) $queue) as $name) {
                if (is_string($name) && trim($name) !== '') {
                    $connection = is_string($connection) && $connection !== '' ? $connection : $default;
                    $queues[] = $connection === $default ? trim($name) : $connection.':'.trim($name);
                }
            }
        };

        $add($default, $config->get("queue.connections.{$default}.queue", 'default'));

        $supervisors = array_merge((array) $config->get('horizon.defaults', []), ...array_values(array_map(fn (mixed $env): array => (array) $env, (array) $config->get('horizon.environments', []))));

        foreach ($supervisors as $supervisor) {
            if (is_array($supervisor) && isset($supervisor['queue'])) {
                $add($supervisor['connection'] ?? $default, $supervisor['queue']);
            }
        }

        $queues = array_values(array_unique($queues));

        return count($queues) > 1 ? implode(',', $queues) : '';
    }

    private function agent(): void
    {
        $this->components->info('The agent');

        if (! $this->input->isInteractive()) {
            $this->line('  Run <comment>php artisan lumberjack:agent</comment> under your process manager: '.self::DOCS.'agent');

            return;
        }

        $platform = (string) select('How does this app run in production? The agent runs next to it, one per server.', AgentSnippets::PLATFORMS, default: 'supervisor');
        [$where, $snippet] = AgentSnippets::for($platform, $this->laravel->basePath());

        if ($snippet === '') {
            $this->line('  Run <comment>php artisan lumberjack:agent</comment> under your process manager: '.self::DOCS.'agent');

            return;
        }

        $this->line('  '.$where);
        $this->newLine();
        $this->line($snippet);
        $this->newLine();
        $this->line('  After a deploy, run <comment>php artisan lumberjack:terminate</comment> so it starts again with the new code.');
    }

    private function finish(AgentClient $agent): void
    {
        $this->components->info('Done');

        if ($this->envChanged) {
            $this->line('  Start (or restart) the agent so it reads the new .env, then check it end to end with <comment>php artisan lumberjack:test</comment>.');
        } elseif ($this->input->isInteractive() && $agent->send(['v' => 1, 'events' => []]) && confirm('The agent is running. Send a test exception through it?')) {
            $this->call('lumberjack:test');
        } else {
            $this->line('  With the agent running, check it end to end with <comment>php artisan lumberjack:test</comment>.');
        }

        $this->newLine();
        $this->line('  Next: '.self::DOCS.'recording (what is recorded), '.self::DOCS.'health-checks, '.self::DOCS.'webhooks (reacting to alerts).');
    }

    /**
     * --checks, validated before anything is written; null when not given.
     *
     * @return list<string>|null
     */
    private function checksOption(): ?array
    {
        $given = $this->stringOption('checks');

        if ($given === null) {
            return null;
        }

        $checks = array_values(array_filter(array_map(trim(...), explode(',', $given))));
        array_map(HealthCheckProvider::call(...), $checks);

        return $checks;
    }

    private function appNamespace(): string
    {
        try {
            return $this->laravel->getNamespace();
        } catch (RuntimeException) {
            return 'App\\';
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function arrayOption(string $name): array
    {
        $values = [];

        foreach ((array) $this->option($name) as $value) {
            array_push($values, ...array_filter(array_map(trim(...), explode(',', (string) $value))));
        }

        return $values;
    }

    private function configString(Repository $config, string $key): ?string
    {
        $value = $config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<InputOption>
     */
    protected function getOptions(): array
    {
        return [
            new InputOption('url', null, InputOption::VALUE_REQUIRED, 'The Lumberjack server\'s URL'),
            new InputOption('token', null, InputOption::VALUE_REQUIRED, 'The project\'s server token'),
            new InputOption('key', null, InputOption::VALUE_REQUIRED, 'The project\'s public key, for browser errors'),
            new InputOption('checks', null, InputOption::VALUE_REQUIRED, 'Health checks, comma separated, e.g. database,cache,disk:s3,queue:redis:emails'),
            new InputOption('layout', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A layout to put @lumberjack in, relative to the app\'s root, or "auto" for the only layout there is'),
            new InputOption('queues', null, InputOption::VALUE_REQUIRED, 'Queues whose size the agent sends, e.g. default,redis:emails'),
            new InputOption('no-verify', null, InputOption::VALUE_NONE, 'Do not check the URL and token with Lumberjack'),
            new InputOption('force', null, InputOption::VALUE_NONE, 'Replace existing values and files without asking'),
        ];
    }
}
