<?php

namespace Fountainhead\SigningRoom\Livewire\Portal;

use Fountainhead\SigningRoom\Models\SigningParty;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

class Landing extends Component
{
    public string $email = '';

    public bool $notFound = false;

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'Indtast din e-mailadresse.',
            'email.email' => 'Indtast en gyldig e-mailadresse.',
        ]);

        $this->notFound = false;

        // Rate limit: 10 attempts per minute per IP
        $key = 'signing-room-login:' . request()->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('email', 'For mange forsøg. Prøv igen om lidt.');

            return;
        }

        RateLimiter::hit($key, 60);

        // Check if email has any signing parties
        $exists = SigningParty::where('email', $this->email)->exists();

        if (! $exists) {
            $this->notFound = true;

            return;
        }

        session(['signing_room_email' => $this->email]);

        $this->redirect(route('signing-room.portal.dashboard'));
    }

    public function render()
    {
        return view('signing-room::portal.landing')
            ->layout('signing-room::layouts.portal', ['title' => 'Underskriftrum']);
    }
}
