<?php

namespace Fountainhead\SigningRoom\Events;

use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PartySignedEvent
{
    use Dispatchable, SerializesModels;

    /**
     * @param  bool  $backfilled  true når SyncIduraSignatureStatus indhenter en
     *     underskrift, hvis efterbehandling aldrig kom igennem (op til et døgn
     *     efter). Listeners der sender "lige underskrevet"-mails kan bruge det.
     */
    public function __construct(
        public SigningEnvelope $envelope,
        public SigningParty $party,
        public bool $backfilled = false,
    ) {}
}
