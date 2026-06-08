<?php

namespace Fountainhead\SigningRoom\Notifications;

use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Fountainhead\SigningRoom\Notifications\Concerns\HasBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class PartyRejectedNotification extends Notification implements ShouldQueue
{
    use HasBranding, Queueable, SerializesModels;

    public function __construct(
        public SigningEnvelope $envelope,
        public SigningParty $rejectedParty,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $adminUrl = route('signing-room.admin.show', $this->envelope);

        $mail = (new MailMessage)
            ->subject('Afvist: ' . $this->envelope->title)
            ->view('signing-room::emails.party-rejected', [
                'envelope' => $this->envelope,
                'rejectedParty' => $this->rejectedParty,
                'adminUrl' => $adminUrl,
            ]);

        return $this->applyBranding($mail, $this->envelope);
    }
}
