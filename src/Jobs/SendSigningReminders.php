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
        // Standardkøen, medmindre hosten vælger en anden: en egen kø, som
        // ingen worker lytter på, betyder at jobbet aldrig kører
        // (Frankston-masters Horizon lytter kun på default m.fl.).
        $this->onQueue(config('signing-room.queue'));
    }

    public function handle(): void
    {
        if (! Schema::hasTable('signing_envelopes')) {
            return;
        }

        $envelopes = SigningEnvelope::query()
            ->whereIn('status', [EnvelopeStatus::Sent, EnvelopeStatus::PartiallySigned])
            ->tap(fn ($query) => SigningEnvelope::onlyProcessable($query))
            ->whereNotNull('reminder_interval')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get()
            // Intervallet i PHP, ikke som MySQL-SQL (NOW() - INTERVAL ... DAY),
            // som hverken SQLite eller PostgreSQL forstår.
            ->filter(fn (SigningEnvelope $envelope) => $envelope->last_reminder_at === null
                || $envelope->last_reminder_at->lte(now()->subDays((int) $envelope->reminder_interval)));

        foreach ($envelopes as $envelope) {
            // Idura-ordren er annulleret, udløbet eller ukendt: ingen påmindelse
            // om et dokument, der ikke kan underskrives.
            if ($envelope->hasOpenBlock('idura_order_')) {
                continue;
            }

            // Kun parter, der har fået deres runde-mail: en part uden
            // notified_at venter på DocumentReady (sync prøver igen), og en
            // påmindelse ville sætte notified_at og få den til at blive
            // sprunget over.
            $unsignedParties = $envelope->unsignedCurrentRoundParties()
                ->where('role', 'signer')
                ->whereNotNull('notified_at')
                ->get();

            $reminded = false;

            foreach ($unsignedParties as $party) {
                $maxReminders = config('signing-room.defaults.max_reminders', 3);
                if ($party->reminder_count >= $maxReminders) {
                    continue;
                }

                // Ikke før der er gået et interval siden partens seneste mail:
                // last_reminder_at er tom for en nyligt sendt kuvert, og en
                // manuel påmindelse sætter kun notified_at.
                if ($party->notified_at->gt(now()->subDays((int) $envelope->reminder_interval))) {
                    continue;
                }

                $party->notify(new SigningReminderNotification($envelope));
                $party->increment('reminder_count');
                $party->update(['notified_at' => now()]);
                $envelope->logEvent(SigningEventType::PartyReminded, $party);
                $reminded = true;
            }

            // Kun når nogen faktisk blev mindet: ellers holder kuvertens
            // interval en part tilbage, som blev sprunget over, fordi den lige
            // havde fået en mail, i næsten to intervaller.
            if ($reminded) {
                $envelope->update(['last_reminder_at' => now()]);
            }
        }
    }
}
