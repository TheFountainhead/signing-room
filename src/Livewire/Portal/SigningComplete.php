<?php

namespace Fountainhead\SigningRoom\Livewire\Portal;

use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Livewire\Component;

class SigningComplete extends Component
{
    public function render()
    {
        // Pull (read-then-forget) so the envelope context is single-use. This
        // prevents stale branding from leaking into a later /complete render
        // on a shared browser, and gives multi-tab signers honest semantics:
        // each /complete page resolves the envelope it just signed, never an
        // older one. (Security review F1+F2, FHT-1962 follow-up.)
        $envelope = null;
        $envelopeUuid = session()->pull('signing_room_active_envelope_uuid');

        if ($envelopeUuid) {
            $envelope = SigningEnvelope::where('uuid', $envelopeUuid)->first();
        }

        $hasCriiptoVerify = (bool) config('signing-room.criipto_verify.client_id');
        $isLoggedIn = (bool) session('signing_room_cpr');

        $layoutData = ['title' => 'Underskrift fuldført'];
        if ($envelope) {
            $layoutData['envelope'] = $envelope;
        }

        return view('signing-room::portal.signing-complete', [
            'hasCriiptoVerify' => $hasCriiptoVerify,
            'isLoggedIn'       => $isLoggedIn,
        ])->layout('signing-room::layouts.portal', $layoutData);
    }
}
