<?php

namespace Fountainhead\SigningRoom\Jobs;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Exceptions\EnvelopeBlockedByViewerException;
use Fountainhead\SigningRoom\Exceptions\EnvelopeOrderNotOpenException;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Services\IduraSignatureService;
use Fountainhead\SigningRoom\Services\SigningRoomService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Schema;

/**
 * ShouldBeUnique: to kørsler må ikke overlappe (fx en Horizon-kø med
 * efterslæb, der tømmes af to workers), så de ikke behandler samme kuvert
 * samtidig.
 */
class SyncIduraSignatureStatus implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const RECORD_GRACE_MINUTES = 5;

    /** Låsen udløber, hvis en kørsel dør, så næste time kan køre. */
    public int $uniqueFor = 3600;

    private const RECORD_BACKFILL_HOURS = 24;

    public function __construct()
    {
        // Standardkøen, medmindre hosten vælger en anden: en egen kø, som
        // ingen worker lytter på, betyder at jobbet aldrig kører
        // (Frankston-masters Horizon lytter kun på default m.fl.).
        $this->onQueue(config('signing-room.queue'));
    }

    public function handle(IduraSignatureService $idura, SigningRoomService $service): void
    {
        if (! Schema::hasTable('signing_envelopes')) {
            return;
        }

        $envelopes = SigningEnvelope::query()
            ->whereIn('status', [EnvelopeStatus::Sent, EnvelopeStatus::PartiallySigned])
            ->whereNotNull('idura_signature_order_id')
            ->tap(fn ($query) => SigningEnvelope::onlyProcessable($query))
            ->get();

        foreach ($envelopes as $envelope) {
            // Én kuvert der fejler, må ikke stoppe synkroniseringen af resten.
            try {
                $inspectedSignatories = $this->syncEnvelope($envelope, $idura, $service);

                // Synkroniseringen lykkedes: en tidligere sync-fejl er løst.
                $envelope->resolveBlocks('sync_failed:');

                // Kun når Idura's signatories faktisk blev set, afgrænser
                // tidspunktet alderen på næste kørsels fund.
                if ($inspectedSignatories) {
                    SigningEnvelope::query()->whereKey($envelope->getKey())->update(['last_synced_at' => now()]);
                }
            } catch (\Throwable $e) {
                // Fx et Idura-nedbrud eller forkerte credentials: én rapport pr.
                // kuvert og fejltype, ikke én i timen.
                $envelope->reportBlockedOnce('sync_failed:'.substr(SigningRoomService::completionFailureReason($e), strlen('completion_failed:')), $e);
            }
        }
    }

    /**
     * @return bool true hvis Idura's signatories blev gennemgået
     */
    private function syncEnvelope(SigningEnvelope $envelope, IduraSignatureService $idura, SigningRoomService $service): bool
    {
        $order = $idura->getOrderStatus($envelope->idura_signature_order_id);

        // Idura kender ikke ordren (slettet eller ukendt): én rapport, ikke en
        // fejl hver time.
        if (! is_array($order) || ! isset($order['status'])) {
            $envelope->reportBlockedOnce('idura_order_missing', new EnvelopeOrderNotOpenException(
                "Kuvert {$envelope->id} ({$envelope->uuid}): Idura returnerer ikke ordren {$envelope->idura_signature_order_id}. Afgør manuelt, om kuverten skal annulleres eller sendes igen."
            ));

            return false;
        }

        // Ordrens status FØR underskrifterne behandles: for en udløbet eller
        // annulleret ordre må intet rykkes, fuldføres eller mailes. Men
        // underskrifter givet før udløb/annullering registreres.
        if ($order['status'] === 'EXPIRED') {
            // Først underskrifterne fra før udløb, så expireEnvelope ser, om
            // alle havde underskrevet (og så rapporterer den tabte PDF).
            $this->recordSignaturesOnly($envelope, $order, $service);
            $service->expireEnvelope($envelope);

            return true;
        }

        if (! in_array($order['status'], ['OPEN', 'CLOSED'], true)) {
            $envelope->reportBlockedOnce(SigningRoomService::orderNotOpenReason($order['status']), new EnvelopeOrderNotOpenException(
                "Kuvert {$envelope->id} ({$envelope->uuid}): Idura-ordren {$envelope->idura_signature_order_id} er {$order['status']}, "
                . 'men kuverten er stadig åben her. Afgør manuelt, om den skal annulleres eller sendes igen.'
            ), ['idura_status' => $order['status']]);

            $this->recordSignaturesOnly($envelope, $order, $service);

            return true;
        }

        // Ordren er OPEN eller CLOSED igen: en tidligere idura_order_-blokering
        // (fx en midlertidigt ukendt ordre) er løst, så påmindelser og
        // rapportering virker igen.
        $envelope->resolveBlocks('idura_order_');

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
                // Fundet ved polling: webhooken kom aldrig, og underskriften kan
                // være gammel. Eventet markeres som indhentet.
                if ($service->handleSigned($party, ['synced_from_polling' => true], backfilled: true, dispatchEvent: self::isSignatureAgeBounded($envelope))) {
                    $handledSignature = true;
                }
            }
        }

        $envelope->refresh();

        // Underskrifter der blev claimet, men hvor resten (CPR, party.signed,
        // PartySignedEvent) aldrig kom igennem. Kun ældre end et par minutter,
        // så sync ikke kapløber med en webhook, der er i gang med netop det.
        // Højst et døgn tilbage: ældre underskrifter giver ikke længere mening
        // at melde som "underskrevet"; eventet markeres som indhentet.
        $envelope->parties()
            ->where('status', 'signed')
            ->where('signed_at', '<', now()->subMinutes(self::RECORD_GRACE_MINUTES))
            ->where('signed_at', '>', now()->subHours(self::RECORD_BACKFILL_HOURS))
            ->get()
            // En underskrift claimet ved polling har signed_at = polling-tid,
            // ikke underskriftstid: samme aldersregel som ved polling.
            ->each(fn ($party) => $service->recordSignature(
                $party,
                backfilled: true,
                dispatchEvent: ! ($party->signature_data['synced_from_polling'] ?? false) || self::isSignatureAgeBounded($envelope),
            ));

        if ($envelope->isCompleted()) {
            // Fuldført, men status overskrevet (fx af en forsinket webhook):
            // completeEnvelope retter status tilbage uden nye mails.
            if ($envelope->status !== EnvelopeStatus::Completed) {
                $service->completeEnvelope($envelope);
            }

            return true;
        }

        if ($envelope->isAllRoundsComplete()) {
            // Alle har underskrevet, men kuverten blev aldrig fuldført (fx
            // fordi closeOrder fejlede). Prøv igen. handleSigned har allerede
            // forsøgt i denne kørsel, hvis den lige blev kaldt.
            if (! $handledSignature) {
                $service->completeEnvelope($envelope);
            }

            return true;
        }

        // Runden er færdig, men blev aldrig rykket (fx fejlede noget efter
        // underskriften). advanceRound er idempotent. En afvist part i runden
        // gør den ikke færdig (isCurrentRoundComplete), så en afvist kuvert
        // rykkes ikke.
        if ($envelope->isCurrentRoundComplete()) {
            $service->advanceRound($envelope);

            return true;
        }

        // En mail i den aktuelle runde kunne ikke lægges i køen (parten står
        // stadig pending uden notified_at): prøv igen. Rører ikke allerede
        // notificerede; fejl i workerens afsendelse ligger i failed_jobs.
        $service->notifyRoundParties($envelope, $envelope->current_round);

        $this->reportLegacyViewerBlockOnce($envelope);

        return true;
    }

    /**
     * Idura oplyser ikke, hvornår der blev underskrevet. Alderen på en
     * underskrift fundet ved polling er afgrænset, hvis kuverten blev oprettet
     * inden for RECORD_BACKFILL_HOURS, eller hvis en tidligere vellykket sync
     * af kuverten (last_synced_at) ligger inden for den tid: så var parten
     * usigneret dengang. Ellers (fx første kørsel hos en host, hvor jobbene
     * aldrig har kørt) er alderen ukendt, og PartySignedEvent affyres ikke:
     * host-listeners fakturerer og mailer pr. event.
     */
    private static function isSignatureAgeBounded(SigningEnvelope $envelope): bool
    {
        $cutoff = now()->subHours(self::RECORD_BACKFILL_HOURS);

        return ($envelope->created_at !== null && $envelope->created_at->gt($cutoff))
            || ($envelope->last_synced_at !== null && $envelope->last_synced_at->gt($cutoff));
    }

    /**
     * Underskrifter, Idura har som SIGNED, registreres uden rundeskift,
     * fuldførelse eller mails.
     */
    private function recordSignaturesOnly(SigningEnvelope $envelope, array $order, SigningRoomService $service): void
    {
        foreach ($order['signatories'] ?? [] as $iduraSignatory) {
            if ($iduraSignatory['status'] !== 'SIGNED') {
                continue;
            }

            $party = $envelope->parties()
                ->where('idura_signatory_id', $iduraSignatory['id'])
                ->first();

            if ($party && $party->status->value !== 'signed') {
                $service->handleSigned($party, ['synced_from_polling' => true], progress: false, backfilled: true, dispatchEvent: self::isSignatureAgeBounded($envelope));
            }
        }
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
