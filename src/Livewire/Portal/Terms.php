<?php

namespace Fountainhead\SigningRoom\Livewire\Portal;

use Livewire\Component;

class Terms extends Component
{
    public function render()
    {
        return view('signing-room::portal.terms')
            ->layout('signing-room::layouts.portal', ['title' => 'Vilkår']);
    }
}
