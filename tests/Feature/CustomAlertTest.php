<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\PendingAlert;
use ExeQue\Lumberjack\Recorder;

covers(PendingAlert::class);

beforeEach(function () {
    app(Recorder::class)->capture();
});

describe('custom alerts', function () {
    it('sends everything set on it, without waiting for the end of the request', function () {
        Lumberjack::alert('Paid')
            ->to('payments')
            ->message('Order 1')
            ->success()
            ->url('https://shop.test/1')
            ->field('Amount', '499 kr', inline: true)
            ->table(['Item'], [['Print']], title: 'Items')
            ->link('Open', 'https://shop.test/1', primary: true)
            ->link('Refund', 'https://shop.test/r', danger: true)
            ->dedupe('order-1', minutes: 5)
            ->send();

        expect($this->sentEvents('alert')[0])->toMatchArray([
            'title' => 'Paid',
            'topic' => 'payments',
            'message' => 'Order 1',
            'level' => 'success',
            'url' => 'https://shop.test/1',
            'fields' => [['name' => 'Amount', 'value' => '499 kr', 'inline' => true]],
            'tables' => [['title' => 'Items', 'headers' => ['Item'], 'rows' => [['Print']]]],
            'links' => [
                ['label' => 'Open', 'url' => 'https://shop.test/1', 'style' => 'primary'],
                ['label' => 'Refund', 'url' => 'https://shop.test/r', 'style' => 'danger'],
            ],
            'dedupe_key' => 'order-1',
            'dedupe_minutes' => 5,
        ]);
    });

    it('keeps the level of the last true condition', function (int $failed, string $level) {
        Lumberjack::alert('Import')->success()->warning($failed > 0)->critical($failed > 100)->send();

        expect($this->sentEvents('alert')[0]['level'])->toBe($level);
    })->with([[0, 'success'], [3, 'warning'], [101, 'critical']]);

    it('applies a when() only when it holds', function () {
        Lumberjack::alert('A')->when(false)->to('skipped')->when(true)->message('kept')->send();

        expect($this->sentEvents('alert')[0])->not->toHaveKey('topic')
            ->and($this->sentEvents('alert')[0]['message'])->toBe('kept');
    });
});
