<?php

namespace Fountainhead\SigningRoom\Jobs;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Enums\SigningEventType;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ExpireSigningEnvelopes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        SigningEnvelope::query()
            ->whereNotIn('status', [
                EnvelopeStatus::Completed,
                EnvelopeStatus::Cancelled,
                EnvelopeStatus::Expired,
            ])
            ->where('expires_at', '<', now())
            ->each(function (SigningEnvelope $envelope) {
                $envelope->update(['status' => EnvelopeStatus::Expired]);
                $envelope->logEvent(SigningEventType::EnvelopeExpired);
            });
    }
}
