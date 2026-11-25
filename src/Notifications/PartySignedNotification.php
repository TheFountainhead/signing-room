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
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $adminUrl = route('signing-room.admin.show', $this->envelope);

        $signers = $this->envelope->parties()->where('role', 'signer');
        $total = (clone $signers)->count();
        $signed = (clone $signers)->where('status', 'signed')->count();

        $mail = (new MailMessage)
            ->subject('Underskrevet af ' . $this->signedParty->name . ': ' . $this->envelope->title)
            ->view('signing-room::emails.party-signed', [
                'envelope' => $this->envelope,
                'signedParty' => $this->signedParty,
                'signedCount' => $signed,
                'signerCount' => $total,
                'adminUrl' => $adminUrl,
            ]);

        return $this->applyBranding($mail, $this->envelope);
    }
}
