<?php

namespace Fountainhead\SigningRoom\Jobs;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Exceptions\EnvelopeBlockedByViewerException;
use Fountainhead\SigningRoom\Exceptions\EnvelopeOrderNotOpenException;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Services\IduraSignatureService;
use Fountainhead\SigningRoom\Services\SigningRoomService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Schema;

class SyncIduraSignatureStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const RECORD_GRACE_MINUTES = 5;

    public function __construct()
    {
        $this->onQueue('signing-room');
    }

    public function handle(IduraSignatureService $idura, SigningRoomService $service): void
    {
        if (! Schema::hasTable('signing_envelopes')) {
            return;
        }

        $envelopes = SigningEnvelope::query()
            ->whereIn('status', [EnvelopeStatus::Sent, EnvelopeStatus::PartiallySigned])
            ->whereNotNull('idura_signature_order_id')
            ->get();

        foreach ($envelopes as $envelope) {
            // Én kuvert der fejler, må ikke stoppe synkroniseringen af resten.
            try {
                $this->syncEnvelope($envelope, $idura, $service);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function syncEnvelope(SigningEnvelope $envelope, IduraSignatureService $idura, SigningRoomService $service): void
    {
        $order = $idura->getOrderStatus($envelope->idura_signature_order_id);

        $handledSignature = false;

        foreach ($order['signatories'] ?? [] as $iduraSignatory) {
            $party = $envelope->parties()
                ->where('idura_signatory_id', $iduraSignatory['id'])
                ->first();

            if (! $party) {
                continue;
            }

            if ($iduraSignatory['status'] === 'SIGNED' && $party->status->value !== 'signed') {
                // Kun et ægte claim tæller: har en webhook netop taget
                // underskriften, har den også selv forsøgt fuldførelsen.
                if ($service->handleSigned($party, ['synced_from_polling' => true])) {
                    $handledSignature = true;
                }
            }
        }

        if ($order['status'] === 'EXPIRED' && $envelope->status !== EnvelopeStatus::Expired) {
            $envelope->update(['status' => EnvelopeStatus::Expired]);

            return;
        }

        // Annulleret hos Idura (eller en anden status, hvor ordren hverken er
        // åben eller lukket af os): hverken rundeskift, mails eller
        // lukkeforsøg. Én rapport med handlingen.
        if (! in_array($order['status'], ['OPEN', 'CLOSED'], true)) {
            $envelope->reportBlockedOnce(SigningRoomService::orderNotOpenReason($order['status']), new EnvelopeOrderNotOpenException(
                "Kuvert {$envelope->id} ({$envelope->uuid}): Idura-ordren {$envelope->idura_signature_order_id} er {$order['status']}, "
                . 'men kuverten er stadig åben her. Afgør manuelt, om den skal annulleres eller sendes igen.'
            ), ['idura_status' => $order['status']]);

            return;
        }

        $envelope->refresh();

        // Underskrifter der blev claimet, men hvor resten (CPR, party.signed,
        // PartySignedEvent) aldrig kom igennem. Kun ældre end et par minutter,
        // så sync ikke kapløber med en webhook, der er i gang med netop det.
        $envelope->parties()
            ->where('status', 'signed')
            ->where('signed_at', '<', now()->subMinutes(self::RECORD_GRACE_MINUTES))
            ->get()
            ->each(fn ($party) => $service->recordSignature($party));

        if ($envelope->isCompleted()) {
            // Fuldført, men status overskrevet (fx af en forsinket webhook):
            // completeEnvelope retter status tilbage uden nye mails.
            if ($envelope->status !== EnvelopeStatus::Completed) {
                $service->completeEnvelope($envelope);
            }

            return;
        }

        if ($envelope->isAllRoundsComplete()) {
            // Alle har underskrevet, men kuverten blev aldrig fuldført (fx
            // fordi closeOrder fejlede). Prøv igen. handleSigned har allerede
            // forsøgt i denne kørsel, hvis den lige blev kaldt.
            if (! $handledSignature) {
                $service->completeEnvelope($envelope);
            }

            return;
        }

        // Runden er færdig, men blev aldrig rykket (fx fejlede noget efter
        // underskriften). advanceRound er idempotent. En afvist part i runden
        // gør den ikke færdig (isCurrentRoundComplete), så en afvist kuvert
        // rykkes ikke.
        if ($envelope->isCurrentRoundComplete()) {
            $service->advanceRound($envelope);

            return;
        }

        $this->reportLegacyViewerBlockOnce($envelope);
    }

    /**
     * Den aktuelle runde blokeres KUN af kopimodtagere fra før 25/11-2026, som
     * stadig er åbne som SIGNER hos Idura. Så kan runden (og ordren) aldrig
     * gøres færdig, og det kræver en manuel handling. Rapporteres én gang pr.
     * kuvert og sæt af signatories, ikke hver time.
     */
    private function reportLegacyViewerBlockOnce(SigningEnvelope $envelope): void
    {
        if (! $envelope->isBlockedOnlyByLegacyViewers()) {
            return;
        }

        $viewers = $envelope->legacyBlockingViewers()->orderBy('idura_signatory_id')->get();
        $signatoryIds = $viewers->pluck('idura_signatory_id');

        $envelope->reportBlockedOnce('legacy_viewer_signatory:' . $signatoryIds->implode(','), new EnvelopeBlockedByViewerException(
            "Kuvert {$envelope->id} ({$envelope->uuid}): slet viewer-signatory "
            . $signatoryIds->implode(', ')
            . " hos Idura (ordre {$envelope->idura_signature_order_id}), og sæt derefter idura_signatory_id = NULL "
            . 'på kopimodtagerens part (id ' . $viewers->pluck('id')->implode(', ') . '). '
            . "Runde {$envelope->current_round} venter kun på kopimodtageren, som Idura har som åben SIGNER."
        ), ['idura_signatory_ids' => $signatoryIds->all()]);
    }
}
