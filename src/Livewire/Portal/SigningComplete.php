<?php

namespace Fountainhead\SigningRoom\Livewire\Portal;

use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Livewire\Component;

class SigningComplete extends Component
{
    public function render()
    {
        $envelope = null;
        $envelopeUuid = session('signing_room_active_envelope_uuid');

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
