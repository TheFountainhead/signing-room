<?php

namespace Fountainhead\SigningRoom\Livewire\Portal;

use Livewire\Component;

class Privacy extends Component
{
    public function render()
    {
        return view('signing-room::portal.privacy')
            ->layout('signing-room::layouts.portal', ['title' => 'Privatlivspolitik']);
    }
}
