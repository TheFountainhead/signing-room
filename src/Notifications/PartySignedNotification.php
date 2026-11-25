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

/**
 * Til afsenderen (creator), hver gang en underskriver har underskrevet.
 */
class PartySignedNotification extends Notification implements ShouldQueue
{
    use HasBranding, Queueable, SerializesModels;

    public function __construct(
        public SigningEnvelope $envelope,
        public SigningParty $signedParty,
        public int $signedCount,
        public int $signerCount,
    ) {}

    /**
     * Tæl underskrifterne nu, ved underskriften, ikke når mailen renderes i
     * køen: så viser mailen det antal der gjaldt, da netop denne part skrev under.
     */
    public static function forParty(SigningEnvelope $envelope, SigningParty $party): self
    {
        $signers = $envelope->parties()->where('role', 'signer');

        return new self(
            $envelope,
            $party,
            (clone $signers)->where('status', 'signed')->count(),
            (clone $signers)->count(),
        );
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $adminUrl = route('signing-room.admin.show', $this->envelope);

        $mail = (new MailMessage)
            ->subject('Underskrevet af ' . $this->signedParty->name . ': ' . $this->envelope->title)
            ->view('signing-room::emails.party-signed', [
                'envelope' => $this->envelope,
                'signedParty' => $this->signedParty,
                'signedCount' => $this->signedCount,
                'signerCount' => $this->signerCount,
                'adminUrl' => $adminUrl,
            ]);

        return $this->applyBranding($mail, $this->envelope);
    }
}
