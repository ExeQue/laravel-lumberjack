<?php

declare(strict_types=1);

// Octane's request events as they look, for tests; Octane itself is not installed.

namespace Laravel\Octane\Events;

use Illuminate\Http\Request;

if (! class_exists(RequestReceived::class)) {
    class RequestReceived
    {
        public function __construct(public mixed $app, public mixed $sandbox, public Request $request)
        {
        }
    }

    class RequestTerminated
    {
        public function __construct(public mixed $app, public mixed $sandbox, public Request $request, public mixed $response = null)
        {
        }
    }
}
