<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Activity\ActivityActors;
use ExeQue\Lumberjack\Activity\Payload;
use ExeQue\Lumberjack\Exceptions\ForgetActorFailedException;
use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\Listeners\RecordsActivity;
use ExeQue\Lumberjack\PendingActivity;
use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Testing\RecordedActivity;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\Activity\Customer;
use Tests\Fixtures\Activity\Order;
use Tests\Fixtures\Activity\OrderPlaced;
use Tests\Fixtures\Activity\RefundIssued;
use Tests\Fixtures\Activity\SignedIn;

covers(PendingActivity::class, Payload::class, RecordsActivity::class, ActivityActors::class, RecordedActivity::class);

beforeEach(function () {
    app(Recorder::class)->capture();
    $this->customer = Customer::make(17);
    $this->order = new Order('9f1c', 499, $this->customer, ['print', 'frame']);
    $this->activity = fn (): array => tap(app(Recorder::class))->flush() ? $this->sentEvents('activity') : [];
});

describe('Lumberjack::activity()', function () {
    it('records its name, group, actor, subject and payload', function () {
        Lumberjack::activity('order.placed')
            ->group('billing')
            ->by($this->customer)
            ->on('order', 42)
            ->on('cart', 7)
            ->on('order', 42)
            ->with(['total' => 499, 'paid_at' => new DateTimeImmutable('2026-11-13 10:00:00+00:00')])
            ->with(['currency' => 'DKK'])
            ->send();

        expect(($this->activity)()[0])->toMatchArray([
            'type' => 'activity',
            'name' => 'order.placed',
            'group' => 'billing',
            'actor' => ['type' => Customer::class, 'id' => '17'],
            'subjects' => [['type' => 'order', 'id' => '42'], ['type' => 'cart', 'id' => '7']],
            'payload' => ['total' => 499, 'paid_at' => '2026-11-13T10:00:00+00:00', 'currency' => 'DKK'],
        ]);
    });

    it('sets no actor unless asked, and none for byUser() without a logged in user', function () {
        Lumberjack::activity('a')->send();
        Lumberjack::activity('b')->byUser()->send();

        expect(($this->activity)())->each->not->toHaveKey('actor');
    });

    it('takes the logged in user as actor with byUser()', function () {
        Auth::setUser(tap(new Illuminate\Foundation\Auth\User())->setAttribute('id', 5));

        Lumberjack::activity('signed.in')->byUser()->send();

        expect(($this->activity)()[0]['actor']['id'])->toBe('5');
    });

    it('sends a model in the payload as its type and key, never its attributes', function () {
        Lumberjack::activity('a')->with(['customer' => $this->customer])->send();

        expect(($this->activity)()[0]['payload']['customer'])->toBe(['type' => Customer::class, 'id' => '17'])
            ->and(json_encode(($this->activity)()))->not->toContain('jane@example.test');
    });
});

describe('an event with #[RecordsLumberjackActivity]', function () {
    it('reads @paths from the event and keeps everything else as written', function () {
        event(new OrderPlaced($this->order, express: true));

        $sent = ($this->activity)()[0];

        expect($sent)->toMatchArray([
            'name' => 'order.placed',
            'group' => 'billing',
            'actor' => ['type' => Customer::class, 'id' => '17'],
            'subjects' => [['type' => 'order', 'id' => '9f1c'], ['type' => Customer::class, 'id' => '17']],
            'payload' => [
                'order' => '9f1c',
                'total' => 499,
                'items' => ['print', 'frame'],
                'channel' => 'web',
                'handle' => '@acme',
                'shipping' => ['express' => true, 'missing' => null],
                'customer' => ['type' => Customer::class, 'id' => '17'],
            ],
        ])->and(json_encode($sent))->not->toContain('jane@example.test');
    });

    it('takes the logged in user with byUser, and records nobody without one', function () {
        event(new SignedIn());
        Auth::setUser(tap(new Illuminate\Foundation\Auth\User())->setAttribute('id', 5));
        event(new SignedIn());

        $sent = ($this->activity)();

        expect($sent[0])->not->toHaveKey('actor')
            ->and($sent[1]['actor']['id'])->toBe('5');
    });

    it('records nothing for an event without the attribute or contract', function () {
        event(new stdClass());
        event('some.string.event', ['payload']);

        expect(($this->activity)())->toBe([]);
    });
});

describe('an event implementing TracksLumberjackActivity', function () {
    it('records the activity it returns, before any attribute, and sends it itself', function () {
        event(new RefundIssued($this->order, 100));

        expect(($this->activity)())->toHaveCount(1)
            ->and(($this->activity)()[0])->toMatchArray([
                'name' => 'order.refunded',
                'subjects' => [['type' => 'order', 'id' => '9f1c']],
                'payload' => ['amount' => 100],
            ]);
    });

    it('records nothing when it returns null', function () {
        event(new RefundIssued($this->order, 0));

        expect(($this->activity)())->toBe([]);
    });
});

describe('forgetting an actor', function () {
    beforeEach(function () {
        config(['lumberjack.url' => 'https://lumberjack.test', 'lumberjack.token' => 'lj_srv_x']);
        app()->forgetInstance(ActivityActors::class);
    });

    it('asks Lumberjack with the server token and answers how many it deleted', function () {
        Http::fake(['lumberjack.test/api/activity/actor' => Http::response(['deleted' => 3])]);

        expect(Lumberjack::forgetActor($this->customer))->toBe(3);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->hasHeader('Authorization', 'Bearer lj_srv_x')
            && $request->data() === ['type' => Customer::class, 'id' => '17']);
    });

    it('throws when Lumberjack does not do it', function () {
        Http::fake(['lumberjack.test/*' => Http::response([], 500)]);

        Lumberjack::forgetActor('user', 17);
    })->throws(ForgetActorFailedException::class);

    it('throws without a url and token', function () {
        config(['lumberjack.token' => null]);
        app()->forgetInstance(ActivityActors::class);

        Lumberjack::forgetActor('user', 17);
    })->throws(ForgetActorFailedException::class);
});

describe('faking', function () {
    it('asserts recorded activities by name, group and check, and forgotten actors', function () {
        Lumberjack::fake();

        event(new OrderPlaced($this->order));
        Lumberjack::activity('cart.created')->send();
        Lumberjack::forgetActor('user', 17);

        Lumberjack::assertActivityRecorded('order.placed', group: 'billing', times: 1);
        Lumberjack::assertActivityRecorded(fn (RecordedActivity $a): bool => $a->isBy(Customer::class, 17) && $a->isOn('order', '9f1c') && $a->payload['total'] === 499);
        Lumberjack::assertActivityNotRecorded('order.refunded');
        Lumberjack::assertActorForgotten('user', 17);

        expect(Lumberjack::activities())->toHaveCount(2)
            ->and(fn () => Lumberjack::assertActivityRecorded('order.refunded'))->toThrow(AssertionFailedError::class);
    });
});
