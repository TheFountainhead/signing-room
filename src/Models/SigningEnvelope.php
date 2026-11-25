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

    public function isCurrentRoundComplete(): bool
    {
        // Kun parter der kan blokere hos Idura afgør, om runden er færdig: alle
        // underskrivere, og kopimodtagere der har et Idura-id. De sidste findes
        // kun i kuverter sendt før 25/11-2026, hvor kopimodtagere blev oprettet
        // hos Idura som SIGNER; Iduras signingSequence venter på dem, så runden
        // er reelt ikke færdig. Nye kopimodtagere har intet Idura-id og
        // blokerer ikke.
        return $this->unsignedCurrentRoundParties()
            ->where(fn ($query) => $query
                ->where('role', 'signer')
                ->orWhereNotNull('idura_signatory_id'))
            ->doesntExist();
    }

    public function isAllRoundsComplete(): bool
    {
        // Mindst én underskriver: en kuvert med kun kopimodtagere må aldrig
        // regnes for underskrevet.
        $signers = $this->parties()->where('role', 'signer');

        return (clone $signers)->exists()
            && (clone $signers)->whereNot('status', 'signed')->doesntExist();
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

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
