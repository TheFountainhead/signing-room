<?php

namespace Fountainhead\SigningRoom\Notifications;

use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SigningReminderNotification extends Notification
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
        $daysLeft = $this->envelope->expires_at?->diffInDays(now());

        return (new MailMessage)
            ->subject('Påmindelse: Dokument venter på din underskrift')
            ->greeting('Hej ' . $notifiable->name)
            ->line('Du har et dokument der venter på din underskrift:')
            ->line('**' . $this->envelope->title . '**')
            ->when($daysLeft !== null, function ($message) use ($daysLeft) {
                $message->line("Deadline: {$this->envelope->expires_at->format('j. F Y')} ({$daysLeft} dage)");
            })
            ->action('Se dokument og underskriv', $signingUrl)
            ->salutation('Frankston ApS');
    }
}
