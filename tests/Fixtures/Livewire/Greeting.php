<?php

declare(strict_types=1);

namespace Tests\Fixtures\Livewire;

use Livewire\Component;

class Greeting extends Component
{
    public string $name = 'world';

    public function render(): string
    {
        return '<div>Hello {{ $name }}</div>';
    }
}
