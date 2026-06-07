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

class DocumentReadyNotification extends Notification implements ShouldQueue
{
    use HasBranding, Queueable, SerializesModels;

    public function __construct(
        public SigningEnvelope $envelope,
        public ?SigningParty $party = null,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $signingUrl = $notifiable->signingUrl();

        $mail = (new MailMessage)
            ->subject('Dokument til underskrift: ' . $this->envelope->title)
            ->view('signing-room::emails.document-ready', [
                'envelope' => $this->envelope,
                'party' => $notifiable,
                'signingUrl' => $signingUrl,
            ]);

        return $this->applyBranding($mail, $this->envelope);
    }
}
