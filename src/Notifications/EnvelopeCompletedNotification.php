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

class EnvelopeCompletedNotification extends Notification implements ShouldQueue
{
    use HasBranding, Queueable, SerializesModels;

    public function __construct(
        public SigningEnvelope $envelope,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        // A signer clicking from his mail client has no portal session, so the
        // envelope-keyed download route would refuse him with a 403. Link him
        // to his own party-keyed route with his signing_token instead — the
        // same idiom DocumentReadyNotification uses. The creator is notified
        // with this very class too (SigningRoomService::notifyCreator) but is
        // an App\Models\User with no token, and reaches the envelope route
        // through its authenticated-admin branch.
        $downloadUrl = $notifiable instanceof SigningParty
            ? route('signing-room.portal.pdf', $notifiable->uuid)
                . '?' . http_build_query(['token' => $notifiable->signing_token])
            : route('signing-room.portal.download', $this->envelope->uuid);

        $mail = (new MailMessage)
            ->subject('Underskrevet: ' . $this->envelope->title)
            ->view('signing-room::emails.envelope-completed', [
                'envelope' => $this->envelope,
                'party' => $notifiable,
                'downloadUrl' => $downloadUrl,
            ]);

        return $this->applyBranding($mail, $this->envelope);
    }
}
