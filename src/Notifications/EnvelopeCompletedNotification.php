<?php

namespace Fountainhead\SigningRoom\Notifications;

use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EnvelopeCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public SigningEnvelope $envelope,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $downloadUrl = route('signing-room.portal.download', $this->envelope->uuid);

        return (new MailMessage)
            ->subject('Underskrevet: ' . $this->envelope->title)
            ->view('signing-room::emails.envelope-completed', [
                'envelope' => $this->envelope,
                'party' => $notifiable,
                'downloadUrl' => $downloadUrl,
            ]);
    }
}
