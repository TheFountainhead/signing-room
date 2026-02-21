<?php

namespace Fountainhead\SigningRoom\Livewire\Admin;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Services\SigningRoomService;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EnvelopeShow extends Component
{
    public SigningEnvelope $signingEnvelope;

    public string $cancellationReason = '';

    public bool $showCancelModal = false;

    public function mount(SigningEnvelope $signingEnvelope): void
    {
        $this->signingEnvelope = $signingEnvelope->load([
            'parties' => fn ($q) => $q->orderBy('signing_round')->orderBy('name'),
            'events' => fn ($q) => $q->with('party')->latest('created_at'),
        ]);
    }

    public function sendEnvelope(): void
    {
        $service = app(SigningRoomService::class);
        $service->sendEnvelope($this->signingEnvelope);

        session()->flash('success', 'Dokumentet er sendt til underskrift.');
        $this->signingEnvelope->refresh();
    }

    public function sendReminder(int $partyId): void
    {
        $party = $this->signingEnvelope->parties()->findOrFail($partyId);

        $party->notify(new \Fountainhead\SigningRoom\Notifications\SigningReminderNotification($this->signingEnvelope));
        $party->increment('reminder_count');
        $party->update(['notified_at' => now()]);

        $this->signingEnvelope->logEvent(
            \Fountainhead\SigningRoom\Enums\SigningEventType::PartyReminded,
            $party,
        );

        session()->flash('success', 'Påmindelse sendt til ' . $party->name);
        $this->signingEnvelope->refresh();
    }

    public function confirmCancel(): void
    {
        $this->showCancelModal = true;
    }

    public function cancelEnvelope(): void
    {
        $service = app(SigningRoomService::class);
        $service->cancelEnvelope($this->signingEnvelope, $this->cancellationReason ?: null);

        $this->showCancelModal = false;
        session()->flash('success', 'Dokumentet er annulleret.');
        $this->signingEnvelope->refresh();
    }

    public function downloadOriginal(): StreamedResponse
    {
        $disk = Storage::disk(config('signing-room.storage.disk', 'local'));

        return $disk->download($this->signingEnvelope->original_document, $this->signingEnvelope->title . '.pdf');
    }

    public function downloadSigned(): StreamedResponse
    {
        $disk = Storage::disk(config('signing-room.storage.disk', 'local'));

        return $disk->download($this->signingEnvelope->signed_document, $this->signingEnvelope->title . ' (signeret).pdf');
    }

    public function render()
    {
        return view('signing-room::admin.envelope-show')
            ->layout('signing-room::layouts.admin', ['title' => $this->signingEnvelope->title]);
    }
}
