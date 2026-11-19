<?php

declare(strict_types=1);

namespace Tests\Fixtures\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;

class Counter extends Component
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
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
