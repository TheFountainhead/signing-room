<?php

namespace Fountainhead\SigningRoom\Notifications;

use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PartyRejectedNotification extends Notification
{

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
