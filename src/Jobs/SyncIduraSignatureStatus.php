<?php

namespace Fountainhead\SigningRoom\Jobs;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Enums\SigningEventType;
use Fountainhead\SigningRoom\Exceptions\EnvelopeBlockedByViewerException;
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
                $service->handleSigned($party, ['synced_from_polling' => true]);
                $handledSignature = true;
            }
        }

        if ($order['status'] === 'EXPIRED' && $envelope->status !== EnvelopeStatus::Expired) {
            $envelope->update(['status' => EnvelopeStatus::Expired]);

            return;
        }

        $envelope->refresh();

        if ($envelope->status === EnvelopeStatus::Completed) {
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
        // underskriften). advanceRound er idempotent.
        if ($envelope->isCurrentRoundComplete()) {
            $service->advanceRound($envelope);

            return;
        }

        $this->reportLegacyViewerBlockOnce($envelope);
    }

    /**
     * Alle underskrivere har underskrevet, men en kopimodtager fra før
     * 25/11-2026 er stadig åben som SIGNER hos Idura. Så kan ordren aldrig
     * lukkes, og det kræver en manuel handling. Det rapporteres én gang pr.
     * kuvert (markeret med et envelope.blocked-event), ikke hver time.
     */
    private function reportLegacyViewerBlockOnce(SigningEnvelope $envelope): void
    {
        $signersOutstanding = $envelope->parties()
            ->where('role', 'signer')
            ->whereNot('status', 'signed')
            ->exists();

        if ($signersOutstanding) {
            return;
        }

        $viewers = $envelope->legacyBlockingViewers()->get();

        if ($viewers->isEmpty()) {
            return;
        }

        if ($envelope->events()->where('event_type', SigningEventType::EnvelopeBlocked->value)->exists()) {
            return;
        }

        $envelope->logEvent(SigningEventType::EnvelopeBlocked, metadata: [
            'reason' => 'legacy_viewer_signatory',
            'idura_signatory_ids' => $viewers->pluck('idura_signatory_id')->all(),
        ]);

        report(new EnvelopeBlockedByViewerException(
            "Kuvert {$envelope->id} ({$envelope->uuid}): slet viewer-signatory "
            . $viewers->pluck('idura_signatory_id')->implode(', ')
            . " hos Idura (ordre {$envelope->idura_signature_order_id}), og sæt derefter idura_signatory_id = NULL "
            . "på kopimodtagerens part (id " . $viewers->pluck('id')->implode(', ') . "). "
            . "Alle underskrivere har underskrevet, men Idura kan ikke lukke ordren, så længe kopimodtageren er åben som SIGNER."
        ));
    }
}
