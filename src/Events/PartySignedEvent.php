<?php

namespace Fountainhead\SigningRoom\Events;

use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PartySignedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public SigningEnvelope $envelope,
        public SigningParty $party,
    ) {}
}
