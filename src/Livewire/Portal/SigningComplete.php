<?php

namespace Fountainhead\SigningRoom\Livewire\Portal;

use Livewire\Component;

class SigningComplete extends Component
{
    public function render()
    {
        return view('signing-room::portal.signing-complete')
            ->layout('signing-room::layouts.portal', ['title' => 'Underskrift fuldført']);
    }
}
