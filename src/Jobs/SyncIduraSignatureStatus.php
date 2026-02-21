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

class SyncIduraSignatureStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(IduraSignatureService $idura, SigningRoomService $service): void
    {
        $envelopes = SigningEnvelope::query()
            ->whereIn('status', [EnvelopeStatus::Sent, EnvelopeStatus::PartiallySigned])
            ->whereNotNull('idura_signature_order_id')
            ->get();

        foreach ($envelopes as $envelope) {
            $order = $idura->getOrderStatus($envelope->idura_signature_order_id);

            foreach ($order['signatories'] ?? [] as $iduraSignatory) {
                $party = $envelope->parties()
                    ->where('idura_signatory_id', $iduraSignatory['id'])
                    ->first();

                if (! $party) {
                    continue;
                }

                if ($iduraSignatory['status'] === 'SIGNED' && $party->status->value !== 'signed') {
                    $service->handleSigned($party, ['synced_from_polling' => true]);
                }
            }

            if ($order['status'] === 'EXPIRED' && $envelope->status !== EnvelopeStatus::Expired) {
                $envelope->update(['status' => EnvelopeStatus::Expired]);
            }
        }
    }
}
