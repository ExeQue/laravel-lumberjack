<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Support\InertiaRequest;
use Illuminate\Support\Facades\Route;

covers(InertiaRequest::class);

beforeEach(function () {
    // What Inertia answers a visit with: the page object, its component first.
    Route::get('/dashboard', fn () => response()->json(['component' => 'Dashboard/Show', 'props' => ['stats' => [1, 2]], 'url' => '/dashboard', 'version' => 'abc'])->header('X-Inertia', 'true'));
    Route::get('/old', fn () => response('', 409)->header('X-Inertia-Location', 'http://localhost/old'));
});

describe('an Inertia request', function () {
    it('is told apart by the kind of visit, with what it asked for', function (array $headers, string $kind, array $context) {
        $this->get('/dashboard', ['X-Inertia' => 'true', 'X-Inertia-Version' => 'abc', ...$headers]);
        app(Recorder::class)->flush();

        $request = $this->sentEvents('request')[0];

        expect($request['route'])->toBe('/dashboard')
            ->and($request['kind'])->toBe($kind)
            ->and($request['context']['inertia'])->toMatchArray(['component' => 'Dashboard/Show', ...$context])
            ->and($this->sentEvents('request_counts')[0]['counts'][0]['kind'])->toBe($kind);
    })->with([
        'a visit' => [[], 'inertia', ['partial' => false, 'only' => [], 'prefetch' => false]],
        'a partial reload' => [['X-Inertia-Partial-Component' => 'Dashboard/Show', 'X-Inertia-Partial-Data' => 'stats, chart'], 'inertia-partial', ['partial' => true, 'only' => ['stats', 'chart']]],
        'a partial reload leaving props out' => [['X-Inertia-Partial-Component' => 'Dashboard/Show', 'X-Inertia-Partial-Except' => 'chart'], 'inertia-partial', ['partial' => true, 'except' => ['chart']]],
        'a prefetch' => [['Purpose' => 'prefetch'], 'inertia-prefetch', ['prefetch' => true]],
        'a reload resetting props' => [['X-Inertia-Reset' => 'feed'], 'inertia', ['reset' => ['feed']]],
    ]);

    it('notes an outdated asset version sent back for a full reload', function () {
        $this->get('/old', ['X-Inertia' => 'true', 'X-Inertia-Version' => 'outdated']);

        expect($this->sentEvents('request')[0]['context']['inertia'])->toMatchArray(['component' => null, 'version_mismatch' => true]);
    });

    it('leaves the first page load an ordinary request', function () {
        $this->get('/dashboard');

        expect($this->sentEvents('request')[0])->not->toHaveKeys(['kind', 'context']);
    });
});

describe('turned off', function () {
    it('records an Inertia request as an ordinary one', function () {
        $this->withConfig(['lumberjack.requests.inertia' => false]);
        Route::get('/dashboard', fn () => response()->json(['component' => 'Dashboard/Show']));

        $this->get('/dashboard', ['X-Inertia' => 'true']);

        expect($this->sentEvents('request')[0])->not->toHaveKeys(['kind', 'context']);
    });
});
