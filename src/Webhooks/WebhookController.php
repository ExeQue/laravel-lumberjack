<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives Lumberjack's webhook: checks the signature and its age, then hands
 * the alert to the app's handlers and to an AlertReceived event.
 */
class WebhookController
{
    public const int TOLERANCE_SECONDS = 300;

    public function __invoke(Request $request, Handlers $handlers): Response
    {
        $secret = (string) config('lumberjack.webhook.secret');
        $timestamp = (string) $request->header('X-Lumberjack-Timestamp');
        $signature = (string) $request->header('X-Lumberjack-Signature');
        $body = $request->getContent();

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        // Signed with our secret, and recent, so a captured request cannot be replayed later.
        // Anything else is answered as if the path did not exist.
        if ($secret === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS || ! hash_equals($expected, $signature)) {
            abort(404);
        }

        $payload = json_decode($body, true);
        $status = is_array($payload) ? Status::fromPayload($payload) : null;

        if (! is_array($payload) || $status === null) {
            // A test message, or an event this version does not know: accepted, nothing to do.
            return response()->noContent();
        }

        $alert = Alert::fromPayload($status, $payload);
        AlertReceived::dispatch($alert);
        $handlers->handle($alert);

        return response()->noContent();
    }
}
