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
            // Grovfilter: kun kuverter med en underskriver, der sidst fik mail
            // før i dag. Intervallet afgøres pr. part nedenfor (ikke med
            // MySQL-SQL som NOW() - INTERVAL ... DAY, som SQLite og PostgreSQL
            // ikke forstår).
            ->whereHas('parties', fn ($p) => $p
                ->where('role', 'signer')
                ->whereNotNull('notified_at')
                ->where('notified_at', '<', now()->startOfDay()))
            ->get();

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

                // Pr. part: først når der er gået reminder_interval døgn siden
                // partens seneste mail, med en times tolerance, så jobbet kl. 09
                // ikke rammer et par sekunder for tidligt. Dækker
                // en nyligt sendt kuvert, en manuel påmindelse og en runde-mail
                // midt i intervallet, uden at holde andre parter tilbage.
                if (! self::isDue($party->notified_at, (int) $envelope->reminder_interval)) {
                    continue;
                }

                $party->notify(new SigningReminderNotification($envelope));
                $party->increment('reminder_count');
                $party->update(['notified_at' => now()]);
                $envelope->logEvent(SigningEventType::PartyReminded, $party);
                $reminded = true;
            }

            // Kun til orientering: intervallet styres pr. part (notified_at).
            if ($reminded) {
                $envelope->update(['last_reminder_at' => now()]);
            }
        }
    }

    private static function isDue(\DateTimeInterface $notifiedAt, int $interval): bool
    {
        return \Illuminate\Support\Carbon::instance($notifiedAt)
            ->lte(now()->subDays(max(1, $interval))->addHour());
    }
}
