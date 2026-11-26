<?php

namespace Fountainhead\SigningRoom\Jobs;

use Fountainhead\SigningRoom\Events\PartySignedEvent;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Affyrer PartySignedEvent i køen i stedet for midt i webhooken.
 *
 * En host-listener, der fejler (fx en mailserver nede), gav før en exception
 * midt i handleSigned, efter at party.signed var logget: eventet blev aldrig
 * affyret igen. Som job forsøges det igen, og fejler det til sidst, ligger det
 * i failed_jobs (queue:retry), så det ikke går tabt.
 *
 * Standardkøen: Frankston-masters Horizon lytter kun på 'default'.
 */
class DispatchPartySignedEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public SigningEnvelope $envelope,
        public SigningParty $party,
        public bool $backfilled = false,
    ) {}

    public function handle(): void
    {
        PartySignedEvent::dispatch($this->envelope, $this->party, $this->backfilled);
    }
}
