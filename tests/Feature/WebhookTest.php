<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\Webhooks\Alert;
use ExeQue\Lumberjack\Webhooks\AlertReceived;
use ExeQue\Lumberjack\Webhooks\Status;
use Illuminate\Support\Facades\Event;

class PaymentFailed extends RuntimeException
{
}

class CardDeclined extends PaymentFailed
{
}

beforeEach(function () {
    $this->withConfig(['lumberjack.webhook.enabled' => true, 'lumberjack.webhook.secret' => 'whsec_test']);
    $this->send = function (array $payload, ?int $timestamp = null, string $secret = 'whsec_test') {
        $body = (string) json_encode($payload);
        $timestamp ??= time();

        return $this->call('POST', '/lumberjack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LUMBERJACK_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_LUMBERJACK_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ], $body);
    };
    $this->escalation = ['event' => 'escalation', 'burst' => 42, 'environment' => 'production', 'issue' => [
        'id' => '01a1', 'reference' => 'SHOP-7', 'title' => CardDeclined::class, 'message' => 'Declined', 'url' => 'https://lumberjack.test/x',
    ]];
});

describe('the Lumberjack webhook', function () {
    it('runs the handlers for the exception class, its subclasses and the status, with the alert', function () {
        $seen = [];
        Lumberjack::onException(PaymentFailed::class, [Status::New, Status::Escalating], function (Alert $alert) use (&$seen) {
            $seen[] = "payment:{$alert->reference}:{$alert->burst}";
        });
        Lumberjack::onException(LogicException::class, Status::Escalating, function () use (&$seen) {
            $seen[] = 'logic';
        });
        Lumberjack::on(Status::Resolved, function () use (&$seen) {
            $seen[] = 'resolved';
        });

        ($this->send)($this->escalation)->assertNoContent();
        ($this->send)([...$this->escalation, 'event' => 'status_change', 'status_change' => ['status' => 'resolved', 'by' => 'Morten']])->assertNoContent();

        expect($seen)->toBe(['payment:SHOP-7:42', 'resolved']);
    });

    it('fires an event for every alert', function () {
        Event::fake([AlertReceived::class]);

        ($this->send)($this->escalation)->assertNoContent();

        Event::assertDispatched(AlertReceived::class, fn (AlertReceived $e) => $e->alert->status === Status::Escalating && $e->alert->isException(PaymentFailed::class));
    });

    it('answers a wrong signature, an old request or an unsigned one as if the path did not exist', function () {
        ($this->send)($this->escalation, secret: 'whsec_other')->assertNotFound();
        ($this->send)($this->escalation, timestamp: time() - 600)->assertNotFound();
        $this->postJson('/lumberjack/webhook', $this->escalation)->assertNotFound();
    });

    it('listens on the path the config gives', function () {
        $this->withConfig(['lumberjack.webhook.enabled' => true, 'lumberjack.webhook.secret' => 'whsec_test', 'lumberjack.webhook.path' => 'hooks/errors']);

        $body = (string) json_encode(['event' => 'test']);
        $timestamp = (string) time();
        $this->call('POST', '/hooks/errors', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LUMBERJACK_TIMESTAMP' => $timestamp,
            'HTTP_X_LUMBERJACK_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test'),
        ], $body)->assertNoContent();
        $this->postJson('/lumberjack/webhook', [])->assertNotFound();
    });

    it('accepts a test message without running anything', function () {
        Lumberjack::on(Status::New, fn () => throw new RuntimeException('should not run'));

        ($this->send)(['event' => 'test', 'message' => 'hello'])->assertNoContent();
    });
});

describe('turned off', function () {
    it('has no webhook route, even with a secret', function () {
        $this->withConfig(['lumberjack.webhook.enabled' => false, 'lumberjack.webhook.secret' => 'whsec_test']);

        expect(app('router')->has('lumberjack.webhook'))->toBeFalse();
    });
});

describe('turned on without a secret', function () {
    it('answers everything as if the path did not exist', function () {
        $this->withConfig(['lumberjack.webhook.enabled' => true, 'lumberjack.webhook.secret' => null]);

        $this->postJson('/lumberjack/webhook', ['event' => 'test'])->assertNotFound();
    });
});

describe('replays', function () {
    it('handles a signed delivery once, even when it is sent again within the window', function () {
        $runs = 0;
        Lumberjack::onException(PaymentFailed::class, Status::Escalating, function () use (&$runs) {
            $runs++;
        });
        $timestamp = time();

        ($this->send)($this->escalation, $timestamp)->assertNoContent();
        ($this->send)($this->escalation, $timestamp)->assertNoContent();
        ($this->send)($this->escalation, $timestamp + 1)->assertNoContent();

        expect($runs)->toBe(2);
    });

    it('handles a delivery again when its handler threw the first time', function () {
        $calls = 0;
        Lumberjack::on(Status::Escalating, function () use (&$calls) {
            if (++$calls === 1) {
                throw new RuntimeException('The pager is down');
            }
        });
        $this->withoutExceptionHandling();
        $timestamp = time();

        expect(fn () => ($this->send)($this->escalation, $timestamp))->toThrow(RuntimeException::class, 'The pager is down');

        ($this->send)($this->escalation, $timestamp)->assertNoContent();
        ($this->send)($this->escalation, $timestamp)->assertNoContent();

        expect($calls)->toBe(2);
    });
});
