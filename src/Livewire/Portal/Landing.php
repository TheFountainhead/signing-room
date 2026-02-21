<?php

namespace Fountainhead\SigningRoom\Livewire\Portal;

use Livewire\Component;

class Landing extends Component
{
    public string $email = '';

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'Indtast din e-mailadresse.',
            'email.email' => 'Indtast en gyldig e-mailadresse.',
        ]);

        session(['signing_room_email' => $this->email]);

        $this->redirect(route('signing-room.portal.dashboard'));
    }

    public function render()
    {
        return view('signing-room::portal.landing')
            ->layout('signing-room::layouts.portal', ['title' => 'Underskriftrum']);
    }
}
