<?php

declare(strict_types=1);

namespace Tests\Fixtures\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

class Counter extends Component
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function doubled(): int
    {
        return $this->count * 2;
    }

    public function explode(): void
    {
        throw new RuntimeException('The counter broke.');
    }

    #[On('reset-counter')]
    public function resetCount(): void
    {
        $this->count = 0;
    }

    public function render(): string
    {
        return '<div>{{ $count }}</div>';
    }
}
