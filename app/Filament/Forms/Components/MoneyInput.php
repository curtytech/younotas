<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\TextInput;

class MoneyInput extends TextInput
{
    protected string $view = 'filament.forms.components.money-input';

    protected function setUp(): void
    {
        parent::setUp();

        $this->numeric()->inputMode('numeric')->minValue(0);
    }
}
