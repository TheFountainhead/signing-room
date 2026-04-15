<?php

namespace Fountainhead\SigningRoom\Livewire\Portal;

use Livewire\Component;

class Landing extends Component
{
    public function render()
    {
        $hasCriiptoVerify = (bool) config('signing-room.criipto_verify.client_id');

        return view('signing-room::portal.landing', [
            'hasCriiptoVerify' => $hasCriiptoVerify,
            'error' => session('error'),
        ])->layout('signing-room::layouts.portal', ['title' => 'Underskriftrum']);
    }
}
