<?php

namespace Fountainhead\SigningRoom\Jobs;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Enums\SigningEventType;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Notifications\SigningReminderNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Schema;

class SendSigningReminders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue('signing-room');
    }

    public function handle(): void
    {
        if (! Schema::hasTable('signing_envelopes')) {
            return;
        }

        $envelopes = SigningEnvelope::query()
            ->whereIn('status', [EnvelopeStatus::Sent, EnvelopeStatus::PartiallySigned])
            ->whereNotNull('reminder_interval')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(function ($q) {
                $q->whereNull('last_reminder_at')
                    ->orWhereRaw('last_reminder_at <= NOW() - INTERVAL reminder_interval DAY');
            })
            ->get();

        foreach ($envelopes as $envelope) {
            $unsignedParties = $envelope->unsignedCurrentRoundParties()
                ->where('role', 'signer')
                ->get();

            foreach ($unsignedParties as $party) {
                $maxReminders = config('signing-room.defaults.max_reminders', 3);
                if ($party->reminder_count >= $maxReminders) {
                    continue;
                }

                $party->notify(new SigningReminderNotification($envelope));
                $party->increment('reminder_count');
                $party->update(['notified_at' => now()]);
                $envelope->logEvent(SigningEventType::PartyReminded, $party);
            }

            $envelope->update(['last_reminder_at' => now()]);
        }
    }
}
