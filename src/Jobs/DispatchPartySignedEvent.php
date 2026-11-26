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
 * affyret igen. Som job ender en fejl i failed_jobs og kan køres igen med
 * queue:retry, så den ikke går tabt.
 *
 * Ét forsøg, ingen automatisk retry: listeners kører i rækkefølge, og en
 * retry efter at den første listener er lykkedes ville køre den igen.
 * Frankston-masters LogSignatureUsage opretter en faktureringsrække pr. kald,
 * så en automatisk retry efter en fejlet NotifyAdminOnSignature ville
 * fakturere to gange. Ved manuel queue:retry gælder det samme; tjek først.
 *
 * Kørselstidspunkt: listeners kører nu efter webhooken, så kuverten kan være
 * rykket eller fuldført, når de kører. Hosts' listeners (Frankston-master,
 * dannebrog-v1: LogSignatureUsage og NotifyAdminOnSignature) læser kun
 * envelope/party-id, oprettelses-bruger og navne, ikke status eller runde
 * (tjekket 26/11-2026). LogSignatureUsage bruger now() som signed_at, så den
 * bliver jobbets tidspunkt.
 *
 * Standardkøen: Frankston-masters og dannebrog-v1's Horizon lytter på
 * 'default', sign.frankston.io's worker på 'default,signing-room'.
 */
class DispatchPartySignedEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

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
