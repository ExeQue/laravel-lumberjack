<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Support\QueueWorkers;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;

it('counts each worker on every queue it takes, once per beat', function () {
    $workers = new QueueWorkers(Cache::store());

    expect($workers->count('redis', 'emails'))->toBeNull();

    $workers->beat('redis', 'emails, default');
    $workers->beat('redis', 'emails');

    expect($workers->count('redis', 'emails'))->toBe(1)
        ->and($workers->count('redis', 'default'))->toBe(1)
        ->and($workers->count('redis', 'other'))->toBeNull();
});

it('forgets a worker that stopped saying it is alive', function () {
    $this->travelTo(now());
    (new QueueWorkers(Cache::store()))->beat('redis', 'emails');

    $this->travel(91)->seconds();

    expect((new QueueWorkers(Cache::store()))->count('redis', 'emails'))->toBe(0);
});

it('keeps a worker running a long job alive past the usual window', function () {
    $this->travelTo(now());
    (new QueueWorkers(Cache::store()))->beat('redis', 'reports', 600);

    $this->travel(300)->seconds();

    expect((new QueueWorkers(Cache::store()))->count('redis', 'reports'))->toBe(1);
});

it('beats from the worker loop', function () {
    event(new Looping('redis', 'emails'));

    expect(app(QueueWorkers::class)->count('redis', 'emails'))->toBe(1);
});
