<?php

namespace Fountainhead\SigningRoom\Services;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Enums\SigningEventType;
use Fountainhead\SigningRoom\Enums\SigningPartyRole;
use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Fountainhead\SigningRoom\Notifications\DocumentReadyNotification;
use Fountainhead\SigningRoom\Notifications\EnvelopeCompletedNotification;
use Fountainhead\SigningRoom\Notifications\PartyRejectedNotification;
use Fountainhead\SigningRoom\Notifications\PartySignedNotification;
use Fountainhead\SigningRoom\Events\PartySignedEvent;
use Fountainhead\SigningRoom\Exceptions\EnvelopeOrderNotOpenException;
use Fountainhead\SigningRoom\Exceptions\SignedDocumentUnavailableException;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SigningRoomService
{
    public function __construct(
        private IduraSignatureService $idura,
    ) {}

    /**
     * Create a new signing envelope with parties.
     */
    public function createEnvelope(
        string $title,
        string $pdfPath,
        array $parties,
        ?string $description = null,
        ?int $expiresInDays = null,
        ?int $reminderInterval = null,
        ?int $createdBy = null,
    ): SigningEnvelope {
        $hasSigner = collect($parties)->contains(
            fn (array $party) => ($party['role'] ?? SigningPartyRole::Signer->value) === SigningPartyRole::Signer->value
        );

        if (! $hasSigner) {
            // En kuvert uden underskrivere ville blive "fuldført" uden en
            // eneste underskrift.
            throw new InvalidArgumentException('En kuvert skal have mindst én underskriver.');
        }

        $expiresInDays ??= config('signing-room.defaults.expires_in_days', 30);
        $reminderInterval ??= config('signing-room.defaults.reminder_interval', 7);

        $totalRounds = max(array_column($parties, 'signing_round') ?: [1]);

        $envelope = SigningEnvelope::create([
            'title' => $title,
            'description' => $description,
            'status' => EnvelopeStatus::Draft,
            'original_document' => $pdfPath,
            'total_rounds' => $totalRounds,
            'current_round' => 1,
            'expires_at' => now()->addDays($expiresInDays),
            'reminder_interval' => $reminderInterval,
            'created_by' => $createdBy,
        ]);

        foreach ($parties as $partyData) {
            $envelope->parties()->create([
                'name' => $partyData['name'],
                'email' => $partyData['email'],
                'phone' => $partyData['phone'] ?? null,
                'cpr_last_four' => $partyData['cpr_last_four'] ?? null,
                'role' => $partyData['role'] ?? SigningPartyRole::Signer->value,
                'signing_round' => $partyData['signing_round'] ?? 1,
                'status' => SigningPartyStatus::Pending,
            ]);
        }

        $envelope->logEvent(SigningEventType::EnvelopeCreated);

        return $envelope->load('parties');
    }

    /**
     * Send an envelope to Idura and notify first-round parties.
     */
    public function sendEnvelope(SigningEnvelope $envelope): SigningEnvelope
    {
        if (! $envelope->parties()->where('role', SigningPartyRole::Signer->value)->exists()) {
            throw new InvalidArgumentException('En kuvert skal have mindst én underskriver.');
        }

        $disk = Storage::disk(config('signing-room.storage.disk', 'local'));
        $pdfContents = $disk->get($envelope->original_document);
        $pdfBase64 = base64_encode($pdfContents);

        $webhookUrl = route('signing-room.webhook');
        $redirectUri = route('signing-room.portal.signing-complete');

        // Create signature order at Idura
        $order = $this->idura->createOrder(
            title: $envelope->title,
            pdfBase64: $pdfBase64,
            documentTitle: $envelope->title,
            webhookUrl: $webhookUrl,
            redirectUri: $redirectUri,
            expiresInDays: (int) now()->diffInDays($envelope->expires_at),
        );

        $envelope->update([
            'idura_signature_order_id' => $order['id'],
        ]);

        // Kun underskrivere oprettes hos Idura. Tidligere blev kopimodtagere
        // også sendt, og fordi rollen gik i det forældede fritekstfelt `role`
        // i stedet for `signatoryRole`, blev de oprettet som SIGNER. En sådan
        // "underskriver" underskriver aldrig, og så afviser Idura
        // closeSignatureOrder med "Signature order has open signatory". Det
        // låste kuvert 35 fast i prod (25/11-2026).
        //
        // Kopimodtageren får sin kopi via EnvelopeCompletedNotification og
        // portalen, som ikke bruger noget Idura-id. En rigtig Idura-VIEWER
        // ville kun kunne se dokumentet mens ordren er åben, medmindre der er
        // evidence validation (CPR), som vi typisk ikke har for en kopimodtager.
        $signatories = $envelope->parties
            ->filter(fn (SigningParty $party) => $this->isSigner($party))
            ->map(fn (SigningParty $party) => [
                'reference' => $party->uuid,
                'signatory_role' => 'SIGNER',
                'signing_sequence' => $party->signing_round,
                'cpr' => $party->cpr_last_four ?: null,
            ])
            ->values()
            ->all();

        $iduraSignatories = $this->idura->addSignatories($order['id'], $signatories);

        // Map Idura signatory IDs and signing links back to our parties
        foreach ($iduraSignatories as $iduraSignatory) {
            $party = $envelope->parties
                ->firstWhere('uuid', $iduraSignatory['reference']);

            if ($party) {
                $party->update([
                    'idura_signatory_id' => $iduraSignatory['id'],
                    'idura_signatory_href' => $iduraSignatory['href'] ?? null,
                ]);
            }
        }

        $envelope->update([
            'status' => EnvelopeStatus::Sent,
        ]);

        $envelope->logEvent(SigningEventType::EnvelopeSent);

        // Notify first-round parties
        $this->notifyRoundParties($envelope, 1);

        // Første runde kan bestå af kopimodtagere alene (fx Frankston-masters
        // sekventielle tilstand, hvor hver part får sin egen runde). Så går
        // vi straks videre til den første runde med underskrivere.
        if ($envelope->isCurrentRoundComplete() && ! $envelope->isAllRoundsComplete()) {
            $this->advanceRound($envelope);
        }

        return $envelope->fresh('parties');
    }

    /**
     * Handle a party signing event from the webhook.
     */
    public function handleSigned(SigningParty $party, ?array $signatureData = null): void
    {
        // Claim underskriften atomisk. En gentaget webhook og sync-jobbet kan
        // behandle samme part samtidig; kun den der faktisk flytter rækken til
        // signed, fortsætter. Ellers kommer der dobbelt-mails og dobbelt
        // rundeskift.
        $claimed = SigningParty::query()
            ->whereKey($party->getKey())
            ->where('status', '!=', SigningPartyStatus::Signed->value)
            ->update([
                'status' => SigningPartyStatus::Signed->value,
                'signed_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $party->refresh();
        $party->update(['signature_data' => $signatureData]);

        $this->recordSignature($party);

        $envelope = $party->envelope;

        if ($envelope->isAllRoundsComplete()) {
            // Underskriften er gemt. Fejler fuldførelsen (Idura, disk), skal
            // den ikke rulle underskriften tilbage eller tie: den rapporteres,
            // og SyncIduraSignatureStatus prøver igen ved næste kørsel.
            // Afsenderen får EnvelopeCompletedNotification i stedet for
            // PartySignedNotification, så han ikke får to mails.
            try {
                $this->completeEnvelope($envelope);
            } catch (\Throwable $e) {
                report($e);
            }

            return;
        }

        if ($envelope->isCurrentRoundComplete()) {
            $this->advanceRound($envelope);
        } else {
            // Betinget: en samtidig webhook kan lige have fuldført kuverten.
            // En ubetinget skrivning ville sætte Completed tilbage til
            // PartiallySigned, og sync ville så fuldføre (og maile) igen.
            SigningEnvelope::query()
                ->whereKey($envelope->getKey())
                ->whereIn('status', [EnvelopeStatus::Sent->value, EnvelopeStatus::PartiallySigned->value])
                ->whereNull('completed_at')
                ->update(['status' => EnvelopeStatus::PartiallySigned->value]);

            $envelope->refresh();
        }

        // Først når kuvertens tilstand er opdateret: en fejlende mail må ikke
        // efterlade kuverten uden rundeskift. Slået fra som standard, se
        // signing-room.notifications.party_signed.
        if (config('signing-room.notifications.party_signed', false)) {
            try {
                $this->notifyCreator($envelope, PartySignedNotification::forParty($envelope, $party));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Det der følger efter selve claimet af en underskrift: CPR fra Idura,
     * party.signed-event og PartySignedEvent.
     *
     * Idempotent: gør intet, hvis party.signed-eventet allerede findes for
     * parten. Kaldes af handleSigned og af SyncIduraSignatureStatus for parter,
     * der blev claimet, men hvor dette trin aldrig blev fuldført.
     */
    public function recordSignature(SigningParty $party): void
    {
        $envelope = $party->envelope;

        $alreadyRecorded = $envelope->events()
            ->where('event_type', SigningEventType::PartySigned->value)
            ->where('signing_party_id', $party->getKey())
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        // CPR er bevis, ikke en forudsætning for underskriften. Fejler Idura
        // her (fx 503), må event og rundeskift ikke gå tabt; CPR kan hentes
        // senere med signing-room:backfill-cpr.
        if ($party->idura_signatory_id && $party->cpr_hash === null) {
            try {
                $cpr = $this->idura->getSignatoryEvidence($party->idura_signatory_id);
                if ($cpr) {
                    $party->cpr = $cpr;
                    $party->save();
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $envelope->logEvent(SigningEventType::PartySigned, $party);

        PartySignedEvent::dispatch($envelope, $party);
    }

    /**
     * Handle a party rejection event from the webhook.
     */
    public function handleRejected(SigningParty $party, ?string $reason = null): void
    {
        if ($party->status === SigningPartyStatus::Rejected) {
            return;
        }

        $party->update([
            'status' => SigningPartyStatus::Rejected,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $envelope = $party->envelope;

        $envelope->logEvent(SigningEventType::PartyRejected, $party, [
            'reason' => $reason,
        ]);

        // Notify the envelope creator
        $this->notifyCreator($envelope, new PartyRejectedNotification($envelope, $party));
    }

    /**
     * Notify all signer parties in a given round.
     */
    public function notifyRoundParties(SigningEnvelope $envelope, int $round): void
    {
        $parties = $envelope->parties()
            ->where('signing_round', $round)
            ->where('role', SigningPartyRole::Signer->value)
            ->get();

        foreach ($parties as $index => $party) {
            // Resend rate limit: 2 requests/sec — stagger queued sends 1s apart
            $party->notify(
                (new DocumentReadyNotification($envelope, $party))->delay($index),
            );

            $party->update([
                'status' => SigningPartyStatus::Notified,
                'notified_at' => now(),
            ]);

            $envelope->logEvent(SigningEventType::PartyNotified, $party);
        }
    }

    /**
     * Validate a PDF document before creating a signature order.
     */
    public function validateDocument(string $pdfBase64): array
    {
        return $this->idura->validateDocument($pdfBase64);
    }

    /**
     * Cancel an envelope, optionally cancelling at Idura as well.
     */
    public function cancelEnvelope(SigningEnvelope $envelope, ?string $reason = null): void
    {
        if ($envelope->idura_signature_order_id) {
            $this->idura->cancelOrder($envelope->idura_signature_order_id);
        }

        $envelope->update([
            'status' => EnvelopeStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $envelope->logEvent(SigningEventType::EnvelopeCancelled, metadata: [
            'reason' => $reason,
        ]);
    }

    /**
     * Advance to the next signing round.
     *
     * Public, så SyncIduraSignatureStatus kan rykke en runde, der blev
     * efterladt urykket (fx hvis noget fejlede efter underskriften). Idempotent:
     * rykker kun, når den nuværende runde faktisk er færdig, og kun én gang pr.
     * runde, også ved samtidige kald.
     *
     * Runder uden parter der skal underskrive (kun kopimodtagere) springes
     * over, så næste underskrivere får deres mail.
     */
    public function advanceRound(SigningEnvelope $envelope): void
    {
        $lastRound = (int) $envelope->parties()->max('signing_round');

        while ($envelope->current_round < $lastRound
            && $envelope->isCurrentRoundComplete()
            && ! $envelope->isAllRoundsComplete()) {
            $currentRound = $envelope->current_round;
            $nextRound = $currentRound + 1;

            // Betinget UPDATE: hvis to processer afslutter runden samtidig,
            // rykker kun den ene runden frem og sender næste rundes mails.
            $advanced = SigningEnvelope::query()
                ->whereKey($envelope->getKey())
                ->where('current_round', $currentRound)
                ->update(['current_round' => $nextRound]);

            if ($advanced === 0) {
                return;
            }

            $envelope->refresh();

            $envelope->logEvent(SigningEventType::RoundAdvanced, metadata: [
                'round' => $nextRound,
            ]);

            $this->notifyRoundParties($envelope, $nextRound);
        }
    }

    /**
     * Complete an envelope: close the Idura order and save the signed PDF.
     *
     * Idempotent: kan kaldes igen efter en fejl (fra handleSigned eller
     * SyncIduraSignatureStatus). Kuverten låses under fuldførelsen, og en
     * kuvert der allerede er Completed, eller hvor ikke alle underskrivere
     * har underskrevet, røres ikke. Så sendes PDF og mails kun én gang.
     *
     * Returnerer true hvis netop dette kald fuldførte kuverten.
     */
    public function completeEnvelope(SigningEnvelope $envelope): bool
    {
        $completed = DB::transaction(function () use ($envelope) {
            $locked = SigningEnvelope::query()
                ->whereKey($envelope->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return false;
            }

            if ($locked->isCompleted()) {
                // Allerede fuldført. Har en samtidig webhook overskrevet
                // status, rettes den tilbage, uden nye mails.
                if ($locked->status !== EnvelopeStatus::Completed) {
                    $locked->update(['status' => EnvelopeStatus::Completed]);
                }

                return false;
            }

            if (! $locked->isAllRoundsComplete()) {
                return false;
            }

            return $this->closeOrderAndStore($locked);
        });

        $envelope->refresh();

        if (! $completed) {
            return false;
        }

        $envelope->logEvent(SigningEventType::EnvelopeCompleted);

        // Every party is told the envelope is final — this mail is their only
        // notification of it, and viewers are attached precisely so they can
        // see the document. Who gets a *key* is decided in the notification:
        // only a party who actually signed receives a token link; everyone
        // else is pointed at the portal to identify themselves with MitID.
        foreach ($envelope->parties as $party) {
            $party->notify(new EnvelopeCompletedNotification($envelope));
        }

        // Also notify the creator
        $this->notifyCreator($envelope, new EnvelopeCompletedNotification($envelope));

        return true;
    }

    /**
     * Luk ordren hos Idura, gem den signerede PDF og markér kuverten Completed.
     *
     * Returnerer false (uden at kaste), hvis ordren hos Idura er CANCELLED
     * eller EXPIRED: så kan den ikke fuldføres, og blokeringen er rapporteret.
     */
    private function closeOrderAndStore(SigningEnvelope $envelope): bool
    {
        $retainDays = config('signing-room.defaults.retain_documents', 7);
        $orderId = $envelope->idura_signature_order_id;

        $current = $this->idura->getOrderStatus($orderId);
        $iduraStatus = $current['status'] ?? null;

        if (in_array($iduraStatus, ['CANCELLED', 'EXPIRED'], true)) {
            // Ordren kan ikke lukkes. Prøv ikke closeSignatureOrder hver time;
            // rapportér én gang, hvad der skal gøres.
            $envelope->reportBlockedOnce('idura_order_' . strtolower($iduraStatus), new EnvelopeOrderNotOpenException(
                "Kuvert {$envelope->id} ({$envelope->uuid}): alle har underskrevet, men Idura-ordren {$orderId} er {$iduraStatus} "
                . 'og kan ikke lukkes. Afgør manuelt, om kuverten skal sendes igen eller annulleres.'
            ), ['idura_status' => $iduraStatus]);

            return false;
        }

        if ($iduraStatus === 'CLOSED') {
            // Et tidligere forsøg lukkede ordren, men fik ikke gemt PDF'en.
            // closeSignatureOrder igen ville fejle; hent i stedet dokumentet,
            // som Idura beholder i retain_documents dage efter lukning.
            $order = $this->idura->getOrderDocuments($orderId);

            if (empty($order['documents'][0]['blob'])) {
                throw new SignedDocumentUnavailableException(
                    "KRITISK: Idura-ordren for kuvert {$envelope->uuid} er lukket, men den signerede PDF kan ikke hentes. "
                    . "Idura sletter dokumentet {$retainDays} dage efter lukning, og vi har ingen kopi. Kræver manuel handling nu."
                );
            }
        } else {
            $order = $this->idura->closeOrder($orderId, $retainDays);
        }

        // Save the signed PDF from the first document's blob
        if (! empty($order['documents'][0]['blob'])) {
            $signedPdf = base64_decode($order['documents'][0]['blob']);
            $storagePath = config('signing-room.storage.path', 'signing-room')
                . '/' . $envelope->uuid . '/signed.pdf';

            $disk = Storage::disk(config('signing-room.storage.disk', 'local'));

            // Diskene er konfigureret med 'throw' => false, så en fejlet upload
            // returnerer false i stedet for at kaste. Uden dette tjek gemmer vi
            // stien til en fil der aldrig kom frem, og Idura sletter sin kopi
            // efter retain_documents dage. Så er dokumentet væk uden spor.
            if ($disk->put($storagePath, $signedPdf) === false) {
                throw new \RuntimeException(
                    "Kunne ikke gemme signeret PDF for kuvert {$envelope->uuid} på sti {$storagePath}."
                );
            }

            $envelope->update([
                'signed_document' => $storagePath,
            ]);
        }

        $envelope->update([
            'status' => EnvelopeStatus::Completed,
            'completed_at' => now(),
        ]);

        return true;
    }

    private function isSigner(SigningParty $party): bool
    {
        $role = $party->role instanceof SigningPartyRole ? $party->role->value : $party->role;

        return ($role ?? SigningPartyRole::Signer->value) === SigningPartyRole::Signer->value;
    }

    /**
     * Send a notification to the envelope creator via anonymous notifiable.
     */
    private function notifyCreator(SigningEnvelope $envelope, $notification): void
    {
        if (! $envelope->created_by) {
            return;
        }

        $creator = \App\Models\User::find($envelope->created_by);

        if ($creator) {
            $creator->notify($notification);
        }
    }
}
