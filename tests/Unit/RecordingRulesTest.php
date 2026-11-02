<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Support\RecordingRules;

describe('recording rules', function () {
    beforeEach(fn () => $this->rules = RecordingRules::fromConfig([
        'requests' => ['ignore' => ['up', 'admin/*'], 'ignore_methods' => ['options'], 'ignore_status' => [404]],
        'queries' => ['slower_than_ms' => 5, 'ignore' => ['*information_schema*'], 'ignore_connections' => ['audit']],
        'jobs' => ['ignore' => ['Laravel\\Scout\\Jobs\\*'], 'ignore_queues' => ['low']],
        'exceptions' => ['ignore' => [InvalidArgumentException::class]],
        'schedule' => ['ignore' => ['artisan horizon:*']],
    ]));

    it('leaves out requests by route, path, method and status', function () {
        expect($this->rules->ignoresRoute('GET', 'up', 'up'))->toBeTrue()
            ->and($this->rules->ignoresRoute('GET', 'admin/{user}', 'admin/7'))->toBeTrue()
            ->and($this->rules->ignoresRoute('OPTIONS', 'orders', 'orders'))->toBeTrue()
            ->and($this->rules->ignoresRoute('GET', 'orders', 'orders'))->toBeFalse()
            ->and($this->rules->ignoresStatus(404))->toBeTrue()
            ->and($this->rules->ignoresStatus(500))->toBeFalse();
    });

    it('leaves out fast queries, matching SQL and whole connections', function () {
        expect($this->rules->ignoresQuery('select 1', 'mysql', 2.0))->toBeTrue()
            ->and($this->rules->ignoresQuery('select * from information_schema.tables', 'mysql', 50.0))->toBeTrue()
            ->and($this->rules->ignoresQuery('insert into logs', 'audit', 50.0))->toBeTrue()
            ->and($this->rules->ignoresQuery('select * from orders', 'mysql', 50.0))->toBeFalse();
    });

    it('leaves out jobs by class and queue, exceptions by class and tasks by name', function () {
        expect($this->rules->ignoresJob('Laravel\\Scout\\Jobs\\MakeSearchable', 'default'))->toBeTrue()
            ->and($this->rules->ignoresJob('App\\Jobs\\SendInvoice', 'low'))->toBeTrue()
            ->and($this->rules->ignoresJob('App\\Jobs\\SendInvoice', 'default'))->toBeFalse()
            ->and($this->rules->ignoresException(new InvalidArgumentException()))->toBeTrue()
            ->and($this->rules->ignoresException(new RuntimeException()))->toBeFalse()
            ->and($this->rules->ignoresTask('artisan horizon:snapshot'))->toBeTrue()
            ->and($this->rules->ignoresTask('artisan reports:send'))->toBeFalse();
    });

    it('records everything when the config says nothing', function () {
        $rules = RecordingRules::fromConfig([]);

        expect($rules->requests && $rules->queries && $rules->jobs && $rules->exceptions && $rules->schedule)->toBeTrue()
            ->and($rules->ignoresRoute('GET', 'up', 'up'))->toBeFalse()
            ->and($rules->ignoresQuery('select 1', 'mysql', 0.1))->toBeFalse();
    });
});
