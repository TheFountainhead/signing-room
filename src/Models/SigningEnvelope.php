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
        return $this->unsignedCurrentRoundParties()->count() === 0;
    }

    public function isAllRoundsComplete(): bool
    {
        return $this->parties()
            ->where('role', 'signer')
            ->whereNot('status', 'signed')
            ->doesntExist();
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

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
