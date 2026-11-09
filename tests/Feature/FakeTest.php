<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\Recorder;
use ExeQue\Lumberjack\Testing\Assertable\AssertableAlert;
use ExeQue\Lumberjack\Testing\Assertable\AssertableEvent;
use ExeQue\Lumberjack\Testing\Assertable\AssertableReport;
use ExeQue\Lumberjack\Testing\EventType;
use ExeQue\Lumberjack\Testing\FakeAgentClient;
use ExeQue\Lumberjack\Testing\LumberjackFake;
use ExeQue\Lumberjack\Testing\RecordedAlert;
use PHPUnit\Framework\AssertionFailedError;

covers(LumberjackFake::class, RecordedAlert::class, FakeAgentClient::class, EventType::class, Lumberjack::class);

describe('any event', function () {
    beforeEach(function () {
        $this->record = fn (array $event) => app(Recorder::class)->record($event);
    });

    it('matches by type, by values with dot notation, and with a closure', function () {
        Lumberjack::fake();
        ($this->record)(['type' => 'log', 'level' => 'error', 'message' => 'one', 'context' => ['order' => 7]]);
        ($this->record)(['type' => 'log', 'level' => 'info', 'message' => 'two']);

        Lumberjack::assertRecorded(EventType::Log);
        Lumberjack::assertRecorded(EventType::Log, times: 2);
        Lumberjack::assertRecorded(EventType::Log, ['level' => 'error', 'context.order' => 7], times: 1);
        Lumberjack::assertRecorded(EventType::Log, fn (array $event): bool => $event['message'] === 'two');
        Lumberjack::assertNotRecorded(EventType::Job);
        Lumberjack::assertNotRecorded(EventType::Log, ['level' => 'debug']);

        expect(Lumberjack::recorded(EventType::Log))->toHaveCount(2);
    });

    it('fails when what was asserted did not happen', function (Closure $assertion) {
        Lumberjack::fake();
        ($this->record)(['type' => 'log', 'message' => 'one']);

        expect($assertion)->toThrow(AssertionFailedError::class);
    })->with([
        'recorded' => [fn () => Lumberjack::assertRecorded(EventType::Job)],
        'times' => [fn () => Lumberjack::assertRecorded(EventType::Log, times: 2)],
        'not recorded' => [fn () => Lumberjack::assertNotRecorded(EventType::Log)],
        'nothing' => [fn () => Lumberjack::assertNothingRecorded()],
    ]);

    it('says what was recorded when an assertion fails', function () {
        Lumberjack::fake();
        ($this->record)(['type' => 'log', 'message' => 'the actual line']);

        expect(fn () => Lumberjack::assertRecorded(EventType::Job, ['status' => 'failed']))
            ->toThrow(AssertionFailedError::class, 'the actual line');
    });

    it('sees what the listeners record, with recording turned off in the app', function () {
        $this->withConfig(['lumberjack.enabled' => false]);
        Lumberjack::fake();

        report(new RuntimeException('Boom'));

        Lumberjack::assertReported(RuntimeException::class, 'Boom');
    });

    it('sends nothing to the real agent', function () {
        $agent = $this->agent;
        Lumberjack::fake();

        Lumberjack::alert('A')->send();

        expect($agent->payloads)->toBe([]);
    });

    it('asks for fake() first', function () {
        Lumberjack::assertNothingRecorded();
    })->throws(RuntimeException::class, 'Lumberjack::fake()');
});

describe('exceptions', function () {
    beforeEach(function () {
        Lumberjack::fake();
        report(new InvalidArgumentException('Bad card'));
    });

    it('counts the class and what extends it, by message and times', function () {
        Lumberjack::assertReported(InvalidArgumentException::class);
        Lumberjack::assertReported(LogicException::class, 'Bad card', times: 1);
        Lumberjack::assertNotReported(RuntimeException::class);
        Lumberjack::assertNotReported(InvalidArgumentException::class, 'Other');
    });

    it('fails with what was reported', function () {
        expect(fn () => Lumberjack::assertReported(RuntimeException::class))
            ->toThrow(AssertionFailedError::class, 'Bad card')
            ->and(fn () => Lumberjack::assertNotReported(InvalidArgumentException::class))
            ->toThrow(AssertionFailedError::class);
    });
});

describe('custom alerts', function () {
    beforeEach(fn () => Lumberjack::fake());

    it('matches on title, topic and level in any combination', function () {
        Lumberjack::alert('Paid')->to('payments')->critical()->field('Amount', '499 kr')->dedupe('k', minutes: 3)->send();
        Lumberjack::alert('Paid')->to('payments')->send();

        Lumberjack::assertAlerted('Paid', times: 2);
        Lumberjack::assertAlerted('Paid', topic: 'payments', level: 'critical', times: 1);
        Lumberjack::assertAlerted(topic: 'payments', times: 2);
        Lumberjack::assertAlerted(fn (RecordedAlert $alert): bool => $alert->dedupeMinutes === 3, level: 'critical');
        Lumberjack::assertNotAlerted('Refunded');
        Lumberjack::assertNotAlerted('Paid', topic: 'other');
        Lumberjack::assertRecorded(EventType::Alert, ['title' => 'Paid'], times: 2);
    });

    it('fills in what the server defaults to', function () {
        Lumberjack::alert('Plain')->send();

        expect(Lumberjack::alerts('Plain')[0])->topic->toBe('default')->level->toBe('info')->fields->toBe([]);
    });

    it('fails with what was sent', function () {
        Lumberjack::assertNothingAlerted();
        Lumberjack::alert('Other')->to('ops')->warning()->send();

        expect(fn () => Lumberjack::assertAlerted('Paid', topic: 'payments'))
            ->toThrow(AssertionFailedError::class, '"Other" (topic: ops, level: warning)')
            ->and(fn () => Lumberjack::assertNothingAlerted())->toThrow(AssertionFailedError::class)
            ->and(fn () => Lumberjack::assertNotAlerted('Other'))->toThrow(AssertionFailedError::class);
    });
});

describe('assertions in a closure', function () {
    beforeEach(function () {
        Lumberjack::fake();
        app(Recorder::class)->record(['type' => 'log', 'level' => 'error', 'message' => 'two', 'context' => ['order' => 7]]);
    });

    it('passes when a record meets them all, and counts those', function () {
        Lumberjack::assertRecorded(EventType::Log, fn (AssertableEvent $event) => $event->has('level', 'error')->has('context.order', 7)->missing('nope')->where('message', fn (string $m): bool => $m === 'two'), times: 1);
    });

    it('fails showing the closest record with the difference marked', function () {
        expect(fn () => Lumberjack::assertRecorded(EventType::Log, fn (AssertableEvent $event) => $event->has('level', 'error')->has('message', 'three')))
            ->toThrow(AssertionFailedError::class, '> message  "two" (expected "three")');
    });

    it('fails on a key that is not there', function () {
        expect(fn () => Lumberjack::assertRecorded(EventType::Log, fn (AssertableEvent $event) => $event->has('nope')))->toThrow(AssertionFailedError::class, 'nope');
    });

    it('works for the negative too', function () {
        Lumberjack::assertNotRecorded(EventType::Log, fn (AssertableEvent $event) => $event->has('message', 'three'));

        expect(fn () => Lumberjack::assertNotRecorded(EventType::Log, fn (AssertableEvent $event) => $event->has('message', 'two')))->toThrow(AssertionFailedError::class);
    });

    it('does not hand a missing key to a typed check', function () {
        app(Recorder::class)->record(['type' => 'log', 'level' => 'error', 'message' => 'x', 'ms' => 3]);

        Lumberjack::assertRecorded(EventType::Log, fn (AssertableEvent $event) => $event->where('ms', fn (int $ms): bool => $ms < 5), times: 1);
    });

    it('counts a closure that answers false as failing', function () {
        expect(fn () => Lumberjack::assertRecorded(EventType::Log, fn (AssertableEvent $event): bool => false))->toThrow(AssertionFailedError::class);
    });

    it('leaves an untyped closure answering for itself', function () {
        Lumberjack::assertRecorded(EventType::Log, fn (array $event): bool => $event['message'] === 'two');
    });
});

describe('assertions on alerts and reports', function () {
    it('checks an alert', function () {
        Lumberjack::fake();
        Lumberjack::alert('Import done')->to('imports')->critical()->link('Open', 'https://example.com')->send();

        Lumberjack::assertAlerted(fn (AssertableAlert $alert) => $alert->hasTitle('Import done')->toTopic('imports')->isCritical()->hasLink('Open', 'https://example.com'), times: 1);

        expect(fn () => Lumberjack::assertAlerted(fn (AssertableAlert $alert) => $alert->isWarning()))->toThrow(AssertionFailedError::class, 'expected "warning"');
    });

    it('checks a report', function () {
        Lumberjack::fake();
        report(new RuntimeException('Boom'));

        Lumberjack::assertReported(RuntimeException::class, fn (AssertableReport $report) => $report->hasMessage('Boom'));
        Lumberjack::assertNotReported(RuntimeException::class, fn (AssertableReport $report) => $report->hasMessage('Other'));

        expect(fn () => Lumberjack::assertReported(RuntimeException::class, fn (AssertableReport $report) => $report->hasMessage('Other')))->toThrow(AssertionFailedError::class, 'expected "Other"');
    });
});
