<?php

namespace Fountainhead\SigningRoom\Notifications;

use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SigningReminderNotification extends Notification
{

    public function __construct(
        public SigningEnvelope $envelope,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $signingUrl = route('signing-room.portal.sign', $notifiable->uuid);

        return (new MailMessage)
            ->subject('Påmindelse: Dokument venter på din underskrift')
            ->view('signing-room::emails.signing-reminder', [
                'envelope' => $this->envelope,
                'party' => $notifiable,
                'signingUrl' => $signingUrl,
            ]);
    }
}
