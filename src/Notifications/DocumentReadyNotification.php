<?php

namespace Fountainhead\SigningRoom\Notifications;

use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentReadyNotification extends Notification
{
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
        $signingUrl = route('signing-room.portal.sign', $notifiable->uuid);

        $mail = (new MailMessage)
            ->subject('Dokument til underskrift: ' . $this->envelope->title)
            ->view('signing-room::emails.document-ready', [
                'envelope' => $this->envelope,
                'party' => $notifiable,
                'signingUrl' => $signingUrl,
            ]);

        $branding = $this->resolveBranding();
        if ($branding) {
            $mail->from(config('mail.from.address'), $branding['company_name']);
        }

        return $mail;
    }

    protected function resolveBranding(): ?array
    {
        $resolver = config('signing-room.branding_resolver');
        if ($resolver && $this->envelope->created_by) {
            return $resolver($this->envelope->created_by);
        }
        return null;
    }
}
