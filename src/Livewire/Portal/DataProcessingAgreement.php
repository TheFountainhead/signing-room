<?php

namespace Fountainhead\SigningRoom\Livewire\Portal;

use Livewire\Component;

class DataProcessingAgreement extends Component
{
    public function render()
    {
        return view('signing-room::portal.dpa')
            ->layout('signing-room::layouts.portal', ['title' => 'Databehandleraftale']);
    }
}
