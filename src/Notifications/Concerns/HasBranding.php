<?php

namespace Fountainhead\SigningRoom\Notifications\Concerns;

use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Illuminate\Notifications\Messages\MailMessage;

trait HasBranding
{
    protected function applyBranding(MailMessage $mail, SigningEnvelope $envelope): MailMessage
    {
        $branding = $this->resolveBranding($envelope);

        if ($branding && config('mail.from.own_domain', false)) {
            // Use creator's own email + company name (customer has DKIM/SPF configured)
            $creator = $envelope->created_by
                ? \App\Models\User::find($envelope->created_by)
                : null;

            if ($creator) {
                $mail->from($creator->email, $branding['company_name'] ?? $creator->name);
            }
        } elseif ($branding) {
            // Use platform's email address but with customer's company name
            $mail->from(config('mail.from.address'), $branding['company_name']);
        }

        return $mail;
    }

    protected function resolveBranding(SigningEnvelope $envelope): ?array
    {
        $resolver = config('signing-room.branding_resolver');
        if ($resolver && $envelope->created_by) {
            return $resolver($envelope->created_by);
        }
        return null;
    }
}
