<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Support\CallerLocation;

describe('the caller location', function () {
    it('is the first line in the app, skipping vendor and the package itself', function () {
        $root = dirname(__DIR__, 2);
        $line = __LINE__ + 1;
        $location = (new CallerLocation($root, $root.'/src'))->find();

        expect($location)->toBe("tests/Unit/CallerLocationTest.php:{$line}");
    });

    it('is unknown when nothing on the stack is app code', function () {
        expect((new CallerLocation('/nowhere', '/nowhere/src'))->find())->toBeNull();
    });
});
