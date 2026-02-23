<?php

namespace Fountainhead\SigningRoom\Livewire\Portal;

use Fountainhead\SigningRoom\Enums\SigningEventType;
use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
use Fountainhead\SigningRoom\Models\SigningParty;
use Fountainhead\SigningRoom\Services\SigningRoomService;
use Livewire\Component;

class SignDocument extends Component
{
    public SigningParty $signingParty;

    public bool $showRejectModal = false;

    public string $rejectionReason = '';

    public function mount(SigningParty $signingParty): void
    {
        $this->signingParty = $signingParty->load('envelope.parties');

        // Always set portal session for this party (ensures PDF access after MitID redirect)
        session(['signing_room_email' => $this->signingParty->email]);

        // Mark as viewed on first access (skip if already signed/rejected)
        if (! $this->signingParty->viewed_at
            && $this->signingParty->status !== SigningPartyStatus::Signed
            && $this->signingParty->status !== SigningPartyStatus::Rejected) {
            $this->signingParty->update([
                'viewed_at' => now(),
                'status' => SigningPartyStatus::Viewed,
            ]);

            $this->signingParty->envelope->logEvent(
                SigningEventType::PartyViewed,
                $this->signingParty,
            );
        }
    }

    public function reject(): void
    {
        $this->validate([
            'rejectionReason' => 'required|string|max:1000',
        ]);

        $service = app(SigningRoomService::class);
        $service->handleRejected($this->signingParty, $this->rejectionReason);

        $this->showRejectModal = false;

        session()->flash('message', 'Du har afvist dokumentet.');
        $this->signingParty->refresh();
    }

    public function render()
    {
        $envelope = $this->signingParty->envelope;
        $allParties = $envelope->parties->groupBy('signing_round');

        return view('signing-room::portal.sign-document', [
            'envelope' => $envelope,
            'allParties' => $allParties,
        ])->layout('signing-room::layouts.portal', ['title' => $envelope->title]);
    }
}
