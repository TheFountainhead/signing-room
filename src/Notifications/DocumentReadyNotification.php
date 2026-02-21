<?php

namespace Fountainhead\SigningRoom\Notifications;

use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentReadyNotification extends Notification
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
        $signingUrl = route('signing-room.portal.sign', $notifiable->uuid);

        return (new MailMessage)
            ->subject('Dokument til underskrift: ' . $this->envelope->title)
            ->greeting('Hej ' . $notifiable->name)
            ->line('Frankston har sendt dig et dokument til digital underskrift:')
            ->line('**' . $this->envelope->title . '**')
            ->when($this->envelope->expires_at, function ($message) {
                $message->line('Deadline: ' . $this->envelope->expires_at->format('j. F Y'));
            })
            ->action('Se dokument og underskriv', $signingUrl)
            ->line('Du bliver bedt om at identificere dig med MitID for at underskrive.')
            ->salutation('Frankston ApS');
    }
}
