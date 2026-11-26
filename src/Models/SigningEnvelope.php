<?php

namespace Fountainhead\SigningRoom\Models;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Enums\SigningEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SigningEnvelope extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => EnvelopeStatus::class,
        'total_rounds' => 'integer',
        'current_round' => 'integer',
        'reminder_interval' => 'integer',
        'retention_days' => 'integer',
        'expires_at' => 'datetime',
        'last_reminder_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completion_started_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $envelope) {
            $envelope->uuid ??= Str::uuid()->toString();
        });
    }

    public function parties(): HasMany
    {
        return $this->hasMany(SigningParty::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SigningEvent::class);
    }

    public function currentRoundParties(): HasMany
    {
        return $this->parties()->where('signing_round', $this->current_round);
    }

    public function unsignedCurrentRoundParties(): HasMany
    {
        return $this->currentRoundParties()
            ->whereNotIn('status', ['signed', 'rejected']);
    }

    /**
     * Parter der skal have underskrevet, før en runde eller kuverten er færdig:
     * alle underskrivere, og kopimodtagere der har et Idura-id.
     *
     * De sidste findes kun i kuverter sendt før 25/11-2026, hvor kopimodtagere
     * blev oprettet hos Idura som SIGNER. Idura venter på dem, både i
     * signingSequence og i closeSignatureOrder ("open signatory"). Nye
     * kopimodtagere har intet Idura-id og blokerer ikke. Samme regel bruges
     * for runde og fuldførelse, så de to aldrig er uenige.
     */
    private static function onlyBlocking(HasMany $parties): HasMany
    {
        return $parties->where(fn ($q) => $q
            ->where('role', 'signer')
            ->orWhereNotNull('idura_signatory_id'));
    }

    /**
     * Blokerende parter i den aktuelle runde, der ikke har underskrevet.
     * En afvist eller fejlet part tæller med: runden er ikke færdig, fordi
     * nogen har afvist, og næste runde må ikke få mails.
     */
    public function unsignedBlockingCurrentRoundParties(): HasMany
    {
        return self::onlyBlocking($this->currentRoundParties())->whereNot('status', 'signed');
    }

    public function isCurrentRoundComplete(): bool
    {
        return $this->unsignedBlockingCurrentRoundParties()->doesntExist();
    }

    /**
     * Kuverten er reelt fuldført: status, tidspunkt eller gemt PDF. Status kan
     * være overskrevet af en samtidig webhook; de to andre felter skrives kun
     * af fuldførelsen.
     */
    public function isCompleted(): bool
    {
        return $this->status === EnvelopeStatus::Completed
            || $this->completed_at !== null
            || $this->signed_document !== null;
    }

    /**
     * Markér åbne blokeringer som løst (envelope.unblocked), fx når
     * fuldførelsen lykkes efter en forbigående fejl. Gør intet uden åbne.
     */
    public function resolveBlocks(?string $reasonPrefix = null): void
    {
        $open = $this->openBlocks()
            ->filter(fn (SigningEvent $event) => $reasonPrefix === null
                || str_starts_with((string) ($event->metadata['reason'] ?? ''), $reasonPrefix));

        if ($open->isNotEmpty()) {
            $this->logEvent(SigningEventType::EnvelopeUnblocked, metadata: ['reason_prefix' => $reasonPrefix]);
        }
    }

    /**
     * Blokeringer, der ikke er løst: logget efter seneste "løst" for alle
     * årsager, eller for et præfiks, der matcher deres årsag.
     *
     * @return \Illuminate\Support\Collection<int, SigningEvent>
     */
    private function openBlocks(): \Illuminate\Support\Collection
    {
        $unblocks = $this->events()
            ->where('event_type', SigningEventType::EnvelopeUnblocked->value)
            ->get(['id', 'metadata']);

        return $this->events()
            ->where('event_type', SigningEventType::EnvelopeBlocked->value)
            ->get()
            ->reject(function (SigningEvent $block) use ($unblocks) {
                $reason = (string) ($block->metadata['reason'] ?? '');

                return $unblocks->contains(fn (SigningEvent $unblock) => $unblock->id > $block->id
                    && (($prefix = $unblock->metadata['reason_prefix'] ?? null) === null || str_starts_with($reason, $prefix)));
            })
            ->values();
    }

    /**
     * Rapportér en blokering, der kræver manuel handling, én gang pr. kuvert og
     * årsag (markeret med et envelope.blocked-event), ikke ved hver kørsel.
     *
     * Returnerer true hvis den blev rapporteret nu.
     */
    public function reportBlockedOnce(string $reason, \Throwable $exception, array $metadata = []): bool
    {
        // Tjek og markør under en kort rækkelås på kuverten, så en webhook og
        // sync, der rammer samme blokering samtidig, ikke begge rapporterer.
        // Rapporten sendes efter låsen.
        $logged = \Illuminate\Support\Facades\DB::transaction(function () use ($reason, $metadata) {
            self::query()->whereKey($this->getKey())->lockForUpdate()->first();

            // Kun åbne blokeringer: en ny fejl efter en løsning skal
            // rapporteres igen.
            $alreadyReported = $this->openBlocks()
                ->contains(fn (SigningEvent $event) => ($event->metadata['reason'] ?? null) === $reason);

            if ($alreadyReported) {
                return false;
            }

            $this->logEvent(SigningEventType::EnvelopeBlocked, metadata: ['reason' => $reason] + $metadata);

            return true;
        });

        if ($logged) {
            report($exception);
        }

        return $logged;
    }

    public function hasRejectedSigner(): bool
    {
        return $this->parties()->where('role', 'signer')->where('status', 'rejected')->exists();
    }

    public function isAllRoundsComplete(): bool
    {
        // Mindst én underskriver: en kuvert med kun kopimodtagere må aldrig
        // regnes for underskrevet.
        return $this->parties()->where('role', 'signer')->exists()
            && $this->unsignedBlockingParties()->doesntExist();
    }

    /**
     * Kopimodtagere i den aktuelle runde fra før rettelsen (25/11-2026), der
     * stadig er åbne som SIGNER hos Idura og holder runden og ordren åben.
     * I prod har kun kuvert 34 (annulleret) og 35 haft sådanne.
     */
    public function legacyBlockingViewers(): HasMany
    {
        return $this->currentRoundParties()
            ->where('role', 'viewer')
            ->whereNotNull('idura_signatory_id')
            ->whereNot('status', 'signed');
    }

    /**
     * Den aktuelle runde venter KUN på gamle kopimodtagere: ingen manglende
     * underskrivere, men mindst én gammel kopimodtager.
     */
    public function isBlockedOnlyByLegacyViewers(): bool
    {
        $legacy = $this->legacyBlockingViewers()->count();

        return $legacy > 0 && $this->unsignedBlockingCurrentRoundParties()->count() === $legacy;
    }

    private function unsignedBlockingParties(): HasMany
    {
        return self::onlyBlocking($this->parties())->whereNot('status', 'signed');
    }

    public function logEvent(
        SigningEventType $type,
        ?SigningParty $party = null,
        ?array $metadata = null,
    ): SigningEvent {
        return $this->events()->create([
            'signing_party_id' => $party?->id,
            'event_type' => $type->value,
            'metadata' => $metadata,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }

    public function folder()
    {
        return $this->belongsTo(
            config('signing-room.folder_model', 'App\\Models\\SigningRoomFolder'),
            'folder_id'
        );
    }

    /**
     * Begræns en forespørgsel til kuverter, de planlagte jobs må røre
     * (signing-room.process_envelopes_created_since).
     */
    public static function onlyProcessable(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $since = config('signing-room.process_envelopes_created_since');

        if ($since === null || $since === '') {
            return $query;
        }

        // Kun Y-m-d. En ugyldig værdi (fx 26/11-2026) behandler ingen kuverter
        // og logges: hellere ingen jobs end hele efterslæbet.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $since);

        if ($date === false || $date->format('Y-m-d') !== (string) $since) {
            \Illuminate\Support\Facades\Log::error('signing-room.process_envelopes_created_since (SIGNING_ROOM_PROCESS_SINCE) er ikke en gyldig dato (Y-m-d); de planlagte jobs springer alle kuverter over.', ['value' => $since]);

            return $query->whereRaw('1 = 0');
        }

        return $query->where('created_at', '>=', $date->format('Y-m-d 00:00:00'));
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
