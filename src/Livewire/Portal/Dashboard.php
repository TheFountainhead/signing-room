<?php

namespace Fountainhead\SigningRoom\Livewire\Portal;

use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
use Fountainhead\SigningRoom\Models\SigningParty;
use Livewire\Component;

class Dashboard extends Component
{
    public function mount(): void
    {
        if (! session('signing_room_email')) {
            $this->redirect(route('signing-room.portal.landing'));
        }
    }

    public function render()
    {
        $email = session('signing_room_email');

        $parties = SigningParty::where('email', $email)
            ->with('envelope')
            ->latest()
            ->get();

        $pending = $parties->filter(fn ($p) => in_array($p->status, [
            SigningPartyStatus::Pending,
            SigningPartyStatus::Notified,
            SigningPartyStatus::Viewed,
        ]));

        $signed = $parties->filter(fn ($p) => $p->status === SigningPartyStatus::Signed);

        $other = $parties->filter(fn ($p) => in_array($p->status, [
            SigningPartyStatus::Rejected,
            SigningPartyStatus::Error,
        ]));

        return view('signing-room::portal.dashboard', [
            'pending' => $pending,
            'signed' => $signed,
            'other' => $other,
        ])->layout('signing-room::layouts.portal', ['title' => 'Mine dokumenter']);
    }
}
