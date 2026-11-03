<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Support\Scrubber;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;

/** Real-looking secrets, none of which may reach the agent. */
const SECRETS = [
    'Bearer-SECRET-abc123', 'KEYSECRET-9f8e7d', 'laravel_session=SESSIONCOOKIE', 'REFRESH-SECRET-777',
    'hunter2', '4111111111111111', 'SIGNEDURLSECRET', 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiI0MiJ9.c2lnbmF0dXJlLXNlY3JldA',
    'AKIAIOSFODNN7EXAMPLE', 'sk_live_51HxSECRETSECRET', 'dbpass-SECRET', 'alice@example.com', 'URLKEY-SECRET-42',
];

function assertNoSecrets(array $payloads): void
{
    $json = json_encode($payloads, JSON_UNESCAPED_SLASHES);

    foreach (SECRETS as $secret) {
        expect($json)->not->toContain($secret);
    }
}

describe('nothing secret leaves the app', function () {
    it('blanks headers, the query string and the session of a request that threw', function () {
        Route::middleware('web')->get('/pay', function () {
            session(['oauth_refresh_token' => 'REFRESH-SECRET-777', 'cart' => 3]);

            throw new RuntimeException('Card declined');
        });

        $this->withHeaders(['Authorization' => 'Bearer Bearer-SECRET-abc123', 'X-Api-Key' => 'KEYSECRET-9f8e7d', 'Cookie' => 'laravel_session=SESSIONCOOKIE'])
            ->get('/pay?card=4111111111111111&password=hunter2&signature=SIGNEDURLSECRET&page=2');
        app()->terminate();

        assertNoSecrets($this->agent->payloads);
        $context = $this->sentEvents('exception')[0]['context'];
        expect($context['request']['headers']['authorization'])->toBe(Scrubber::FILTERED)
            ->and($context['request']['url'])->toEndWith('/pay?card=[filtered]&page=[filtered]&password=[filtered]&signature=[filtered]')
            ->and($context)->not->toHaveKey('session')
            ->and($this->sentEvents('request')[0]['url'])->toContain('signature=[filtered]');
    });

    it('sends only the session keys and query keys the app allows', function () {
        $this->withConfig(['lumberjack.scrub' => ['session_keys' => ['cart', 'oauth_refresh_token'], 'query_keys' => ['page']]]);
        Route::middleware('web')->get('/pay', function () {
            session(['oauth_refresh_token' => 'REFRESH-SECRET-777', 'cart' => 3]);

            throw new RuntimeException('Card declined');
        });

        $this->get('/pay?page=2&card=4111111111111111');
        app()->terminate();

        assertNoSecrets($this->agent->payloads);
        $context = $this->sentEvents('exception')[0]['context'];
        // Allowed, but a sensitive key is still blanked.
        expect($context['session'])->toBe(['oauth_refresh_token' => Scrubber::FILTERED, 'cart' => 3])
            ->and($context['request']['url'])->toEndWith('/pay?card=[filtered]&page=2');
    });

    it('blanks log context and credentials in log messages', function () {
        config()->set('logging.channels.lumberjack', ['driver' => 'lumberjack']);

        Log::channel('lumberjack')->info('Calling the API with Bearer Bearer-SECRET-abc123', [
            'password' => 'hunter2',
            'user' => ['email' => 'bob', 'api_token' => 'KEYSECRET-9f8e7d'],
            'dsn' => 'mysql://forge:dbpass-SECRET@10.0.0.5/app',
            'jwt' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiI0MiJ9.c2lnbmF0dXJlLXNlY3JldA',
            'aws' => 'key AKIAIOSFODNN7EXAMPLE',
            'stripe' => 'sk_live_51HxSECRETSECRET',
        ]);
        app()->terminate();

        assertNoSecrets($this->agent->payloads);
        expect($this->sentEvents('log')[0]['context']['dsn'])->toBe('mysql://forge:[filtered]@10.0.0.5/app')
            ->and($this->sentEvents('log')[0]['message'])->toBe('Calling the API with Bearer [filtered]');
    });

    it('blanks Laravel Context sent with an exception', function () {
        Context::add('access_token', 'REFRESH-SECRET-777');
        Context::add('tenant', 'acme');

        report(new RuntimeException('boom'));
        app()->terminate();

        assertNoSecrets($this->agent->payloads);
        expect($this->sentEvents('exception')[0]['context']['laravel'])->toBe(['access_token' => Scrubber::FILTERED, 'tenant' => 'acme']);
    });

    it('blanks the bindings of a failed query and DSN passwords in exception messages', function () {
        report(new QueryException('mysql', 'select * from users where email = ? and password = ?', ['alice@example.com', 'hunter2'], new PDOException('SQLSTATE[42S02]: Base table or view not found')));
        report(new RuntimeException('Could not connect to redis://default:dbpass-SECRET@cache:6379'));
        app()->terminate();

        assertNoSecrets($this->agent->payloads);
        expect($this->sentEvents('exception')[0]['message'])->toContain('SQL: select * from users where email = ? and password = ?');
    });

    it('blanks query-string values of URLs inside exception, log and health check messages', function () {
        $this->withConfig(['lumberjack.scrub.query_keys' => ['page'], 'lumberjack.health.cooldown' => 0]);
        config()->set('logging.channels.lumberjack', ['driver' => 'lumberjack']);

        report(new RuntimeException('Client error: `GET https://api.example.com/v1/charges?api_key=URLKEY-SECRET-42&page=2` resulted in a `401 Unauthorized` response'));
        Log::channel('lumberjack')->warning('Retrying https://api.example.com/x?sig=URLKEY-SECRET-42 (attempt 2)');
        Lumberjack::healthChecks()->check('api', fn () => throw new RuntimeException(
            'cURL error 28: Operation timed out for https://api.example.com/health?token=URLKEY-SECRET-42',
        ));
        Lumberjack::health('api')->run();
        app()->terminate();

        assertNoSecrets($this->agent->payloads);
        expect($this->sentEvents('exception')[0]['message'])->toBe('Client error: `GET https://api.example.com/v1/charges?api_key=[filtered]&page=2` resulted in a `401 Unauthorized` response')
            ->and($this->sentEvents('log')[0]['message'])->toBe('Retrying https://api.example.com/x?sig=[filtered] (attempt 2)')
            ->and($this->sentEvents('health')[0]['message'])->toBe('cURL error 28: Operation timed out for https://api.example.com/health?token=[filtered]');
    });

    it('blanks queued job parameters', function () {
        $job = new class () {
            public string $email = 'bob';

            public string $password = 'hunter2';

            public string $apiKey = 'KEYSECRET-9f8e7d';
        };

        event(new JobQueued('redis', 'default', 'uuid-1', $job, (string) json_encode(['uuid' => 'uuid-1', 'displayName' => 'App\\Jobs\\Signup']), null));
        app(Recorder::class)->flush();

        assertNoSecrets($this->agent->payloads);
        expect($this->sentEvents('job')[0]['parameters'])->toMatchArray(['email' => 'bob', 'password' => Scrubber::FILTERED, 'apiKey' => Scrubber::FILTERED]);
    });

    it('blanks command arguments and options', function () {
        $input = new ArrayInput(['user' => 'bob', '--password' => 'hunter2', '--token' => 'KEYSECRET-9f8e7d'], new InputDefinition([
            new InputArgument('user'),
            new InputOption('password', null, InputOption::VALUE_REQUIRED),
            new InputOption('token', null, InputOption::VALUE_REQUIRED),
        ]));

        event(new CommandStarting('users:reset', $input, new NullOutput()));
        event(new CommandFinished('users:reset', $input, new NullOutput(), 0));

        assertNoSecrets($this->agent->payloads);
        expect($this->sentEvents('command')[1]['options'])->toBe(['password' => Scrubber::FILTERED, 'token' => Scrubber::FILTERED]);
    });

    it('blanks span attributes', function () {
        $recorder = app(Recorder::class);
        $recorder->startRun();
        $recorder->record(['type' => 'span', 'kind' => 'http', 'name' => 'GET', 'target' => 'api.example.com/x', 'attributes' => ['authorization' => 'Bearer Bearer-SECRET-abc123', 'status' => 200]]);
        $recorder->flush();

        assertNoSecrets($this->agent->payloads);
        expect($this->sentEvents('span')[0]['attributes'])->toBe(['authorization' => Scrubber::FILTERED, 'status' => 200]);
    });

    it('takes extra keys and patterns, or only the app\'s own keys', function () {
        $scrubber = Scrubber::fromConfig(['keys' => ['cpr'], 'patterns' => ['/\d{6}-\d{4}/']]);
        expect($scrubber->context(['cpr' => 'x', 'note' => 'cpr 010203-1234', 'password' => 'y']))->toBe(['cpr' => '[filtered]', 'note' => 'cpr [filtered]', 'password' => '[filtered]']);

        $only = Scrubber::fromConfig(['keys' => ['cpr'], 'replace_defaults' => true]);
        expect($only->context(['cpr' => 'x', 'password' => 'y']))->toBe(['cpr' => '[filtered]', 'password' => 'y'])
            // The old shape, a list of extra keys, still works.
            ->and(Scrubber::fromConfig(['cpr'])->context(['cpr' => 'x', 'token' => 'y']))->toBe(['cpr' => '[filtered]', 'token' => '[filtered]']);
    });
});
