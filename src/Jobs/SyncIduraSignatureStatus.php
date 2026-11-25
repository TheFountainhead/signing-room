<?php

namespace Fountainhead\SigningRoom\Jobs;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
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

        // Alle underskrivere har underskrevet, men kuverten blev aldrig
        // fuldført (fx fordi closeOrder fejlede). Prøv igen. handleSigned har
        // allerede forsøgt i denne kørsel, hvis den lige blev kaldt.
        $envelope->refresh();

        if (! $handledSignature
            && $envelope->status !== EnvelopeStatus::Completed
            && $envelope->isAllRoundsComplete()) {
            $service->completeEnvelope($envelope);
        }
    }
}
