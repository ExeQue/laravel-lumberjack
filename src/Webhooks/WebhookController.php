<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

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

        // The signature is unique to its timestamp and body: one seen before is a replay
        // within the window (or the server retrying a delivery that got through), so it
        // is accepted and not handled again. A cache that is down lets it through.
        if (! $this->firstDelivery($signature)) {
            return response()->noContent();
        }

        $payload = json_decode($body, true);
        $status = is_array($payload) ? Status::fromPayload($payload) : null;

        if (! is_array($payload) || $status === null) {
            // A test message, or an event this version does not know: accepted, nothing to do.
            return response()->noContent();
        }

        $alert = Alert::fromPayload($status, $payload);

        // A handler that throws answers 500 and Lumberjack sends the same delivery again;
        // that one must be handled, not taken for a replay.
        try {
            AlertReceived::dispatch($alert);
            $handlers->handle($alert);
        } catch (Throwable $e) {
            $this->forgetDelivery($signature);

            throw $e;
        }

        return response()->noContent();
    }

    private function firstDelivery(string $signature): bool
    {
        try {
            return Cache::add(self::deliveryKey($signature), true, self::TOLERANCE_SECONDS * 2);
        } catch (Throwable $e) {
            @error_log('Lumberjack could not check a webhook for a replay: '.$e->getMessage());

            return true;
        }
    }

    private function forgetDelivery(string $signature): void
    {
        try {
            Cache::forget(self::deliveryKey($signature));
        } catch (Throwable) {
        }
    }

    private static function deliveryKey(string $signature): string
    {
        return 'lumberjack:webhook:'.hash('sha256', $signature);
    }
}
