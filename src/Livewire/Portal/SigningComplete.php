<?php

namespace Fountainhead\SigningRoom\Livewire\Portal;

use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Livewire\Component;

class SigningComplete extends Component
{
    public function render()
    {
        // Pull (read-then-forget) so the envelope context is single-use. This
        // narrows the shared-browser branding-leak window (security review F1+F2)
        // and matches the convention already used in CriiptoAuthController for
        // signing_room_oauth_state. NOTE: this is a temporal fix only — the
        // session UUID is a single global slot, so a concurrent multi-tab
        // race (tab A on /sign/A, tab B clobbers UUID to B, tab A signs and
        // resolves B's branding) is NOT solved here; that requires per-envelope
        // URL-param routing which is tracked as a separate follow-up.
        $envelope = null;
        $envelopeUuid = session()->pull('signing_room_active_envelope_uuid');

        if ($envelopeUuid) {
            $envelope = SigningEnvelope::where('uuid', $envelopeUuid)->first();
        }

        // Same single-use convention for the party: resolve the actual outcome
        // from our own DB (updated synchronously on in-app reject, via webhook
        // for Idura-side rejections) instead of trusting anything in the URL.
        // If the webhook hasn't landed yet, this falls back to the signed copy
        // — same as the pre-existing behavior.
        $partyUuid = session()->pull('signing_room_active_party_uuid');
        $party = $partyUuid ? SigningParty::where('uuid', $partyUuid)->first() : null;
        $rejected = $party?->status === SigningPartyStatus::Rejected;

        $hasCriiptoVerify = (bool) config('signing-room.criipto_verify.client_id');
        $isLoggedIn = (bool) session('signing_room_cpr');

        $layoutData = ['title' => $rejected ? 'Dokument afvist' : 'Underskrift fuldført'];
        if ($envelope) {
            $layoutData['envelope'] = $envelope;
        }

        return view('signing-room::portal.signing-complete', [
            'hasCriiptoVerify' => $hasCriiptoVerify,
            'isLoggedIn'       => $isLoggedIn,
            'rejected'         => $rejected,
        ])->layout('signing-room::layouts.portal', $layoutData);
    }
}
