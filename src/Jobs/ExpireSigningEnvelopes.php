<?php

namespace Fountainhead\SigningRoom\Jobs;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Services\SigningRoomService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Schema;

class ExpireSigningEnvelopes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        // Standardkøen, medmindre hosten vælger en anden: en egen kø, som
        // ingen worker lytter på, betyder at jobbet aldrig kører
        // (Frankston-masters Horizon lytter kun på default m.fl.).
        $this->onQueue(config('signing-room.queue'));
    }

    public function handle(SigningRoomService $service): void
    {
        if (! Schema::hasTable('signing_envelopes')) {
            return;
        }

        SigningEnvelope::query()
            ->whereNotIn('status', [
                EnvelopeStatus::Completed,
                EnvelopeStatus::Cancelled,
                EnvelopeStatus::Expired,
            ])
            ->where('expires_at', '<', now())
            ->tap(fn ($query) => SigningEnvelope::onlyProcessable($query))
            // eachById, ikke each(): each() pager med OFFSET, og når rækker
            // skifter status under kørslen, springes andre over. Ingen
            // returværdi: callbacken må ikke stoppe gennemløbet.
            ->eachById(function (SigningEnvelope $envelope) use ($service): void {
                // Lokalt udløb: Idura-ordren kan stadig være åben.
                $service->expireEnvelope($envelope, iduraExpired: false);
            });
    }
}
