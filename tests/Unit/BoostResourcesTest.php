<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\Health\HealthCheckHandle;
use ExeQue\Lumberjack\Health\HealthRegistry;
use ExeQue\Lumberjack\Webhooks\Handlers;
use Illuminate\Support\Facades\Blade;

// A cheap guard against the AI guidelines drifting from the API they describe.

const BOOST_GUIDELINE = __DIR__.'/../../resources/boost/guidelines/core.blade.php';
const BOOST_SKILL = __DIR__.'/../../resources/boost/skills/lumberjack-development/SKILL.md';

function boostText(): string
{
    return file_get_contents(BOOST_GUIDELINE).file_get_contents(BOOST_SKILL);
}

/**
 * @return list<string>
 */
function boostMatches(string $pattern): array
{
    preg_match_all($pattern, boostText(), $matches);

    return array_values(array_unique($matches[1]));
}

describe('the Boost resources', function () {
    it('render the guideline as Blade, with the directive left as text', function () {
        $rendered = Blade::render(file_get_contents(BOOST_GUIDELINE));

        expect($rendered)->toContain('`@lumberjack` in the layout')
            ->toContain("<head>\n    @lumberjack\n</head>")
            ->toContain('<code-snippet name="Register health checks in boot()" lang="php">')
            ->not->toContain('<script');
    });

    it('give the skill its name and a description', function () {
        expect(file_get_contents(BOOST_SKILL))->toMatch('/\A---\nname: lumberjack-development\ndescription: "Use when [^"]+"\n---\n/');
    });

    it('only name facade methods that exist', function () {
        $names = boostMatches('/Lumberjack::(\w+)\(/');

        expect($names)->toContain('healthChecks', 'health', 'healthStates', 'on', 'onException');

        foreach ($names as $name) {
            expect(method_exists(Lumberjack::class, $name) || method_exists(Handlers::class, $name))->toBeTrue("Lumberjack::{$name}() does not exist");
        }
    });

    it('only name health handle methods that exist', function () {
        foreach (boostMatches('/Lumberjack::health\([^)]*\)->(\w+)\(/') as $name) {
            expect(method_exists(HealthCheckHandle::class, $name))->toBeTrue("->{$name}() is not on the health handle");
        }
    });

    it('only chain registry methods with parameters that exist', function () {
        preg_match_all('/^\s*->(\w+)\((.*)\);?\s*(?:\/\/.*)?$/m', boostText(), $calls, PREG_SET_ORDER);

        expect($calls)->not->toBeEmpty();

        foreach ($calls as [, $method, $arguments]) {
            expect(method_exists(HealthRegistry::class, $method))->toBeTrue("->{$method}() is not on the registry");

            $parameters = array_map(fn (ReflectionParameter $parameter) => $parameter->getName(), (new ReflectionMethod(HealthRegistry::class, $method))->getParameters());
            preg_match_all('/(?<![\w\'"])([a-z]\w*): /', $arguments, $named);

            expect(array_diff($named[1], $parameters))->toBe([], "->{$method}() has no such parameter");
        }
    });

    it('list every built-in check of the registry', function () {
        $listed = boostMatches('/^\| `(\w+)\(/m');
        $builtIns = array_values(array_diff(
            array_map(fn (ReflectionMethod $method) => $method->getName(), (new ReflectionClass(HealthRegistry::class))->getMethods(ReflectionMethod::IS_PUBLIC)),
            ['__construct', 'add', 'check', 'isEmpty', 'checks', 'find', 'every', 'everyMinutes'],
        ));

        expect($builtIns)->toHaveCount(15)
            ->and($listed)->toEqualCanonicalizing($builtIns);
    });
});
