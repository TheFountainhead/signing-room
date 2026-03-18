<?php

namespace Fountainhead\SigningRoom\Notifications;

use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EnvelopeCompletedNotification extends Notification
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
        $downloadUrl = route('signing-room.portal.download', $this->envelope->uuid);

        $mail = (new MailMessage)
            ->subject('Underskrevet: ' . $this->envelope->title)
            ->view('signing-room::emails.envelope-completed', [
                'envelope' => $this->envelope,
                'party' => $notifiable,
                'downloadUrl' => $downloadUrl,
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
