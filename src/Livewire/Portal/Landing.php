<?php

namespace Fountainhead\SigningRoom\Livewire\Portal;

use Livewire\Component;

class Landing extends Component
{
    public function render()
    {
        return view('signing-room::portal.landing')
            ->layout('signing-room::layouts.portal', ['title' => 'Underskriftrum']);
    }
}
