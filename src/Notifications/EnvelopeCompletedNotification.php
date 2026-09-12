<?php

namespace Fountainhead\SigningRoom\Notifications;

use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
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
        // same idiom DocumentReadyNotification uses. A signer only ever reaches
        // the one document he signed.
        //
        // Only a party who actually signed gets that token: it is a permanent,
        // forwardable key to the signed PDF. A viewer — or a party left at
        // rejected/error — is still told the document is final, but is sent to
        // the portal, where MitID decides what he may see. Withholding the key
        // is not the same as withholding the news.
        $isSigner = $notifiable instanceof SigningParty
            && $notifiable->status === SigningPartyStatus::Signed;

        if ($isSigner) {
            $downloadUrl = route('signing-room.portal.pdf', $notifiable->uuid)
                . '?' . http_build_query([
                    'token' => $notifiable->signing_token,
                    'download' => 1,
                ]);
            $ctaLabel = 'Download signeret dokument';
        } elseif ($notifiable instanceof SigningParty) {
            $downloadUrl = route('signing-room.portal.landing');
            $ctaLabel = 'Se dokumentet i underskriftrummet';
        } else {
            // The creator is an App\Models\User, not a party. The download
            // route requires the viewer to BE a party (routes/portal.php:50),
            // and createEnvelope never inserts him as one — so that link 403s
            // for him. He is an admin, so send him to the admin page, which
            // his session grants. Logged out he meets /login first, hence a
            // label that promises a page rather than a file.
            $downloadUrl = route('signing-room.admin.show', $this->envelope->uuid);
            $ctaLabel = 'Åbn dokumentet i administrationen';
        }

        $mail = (new MailMessage)
            ->subject('Underskrevet: ' . $this->envelope->title)
            ->view('signing-room::emails.envelope-completed', [
                'envelope' => $this->envelope,
                'party' => $notifiable,
                'downloadUrl' => $downloadUrl,
                'ctaLabel' => $ctaLabel,
            ]);

        return $this->applyBranding($mail, $this->envelope);
    }
}
