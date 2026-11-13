<?php

declare(strict_types=1);

namespace Tests\Fixtures\Activity;

use ExeQue\Lumberjack\Activity\Actor;
use ExeQue\Lumberjack\Contracts\ActsAsLumberjackActor;
use Illuminate\Foundation\Auth\User;

/**
 * A user model that is known to Lumberjack by its client name, not its class and key.
 */
class ApiClient extends User implements ActsAsLumberjackActor
{
    protected $guarded = [];

    public static function make(int $id, string $client): self
    {
        $model = new self(['client' => $client]);
        $model->setAttribute('id', $id);

        return $model;
    }

    public function toLumberjackActor(): Actor
    {
        return new Actor('api-client', $this->getAttribute('client'));
    }
}
