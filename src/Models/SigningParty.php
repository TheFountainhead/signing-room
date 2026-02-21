<?php

namespace Fountainhead\SigningRoom\Models;

use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class SigningParty extends Model
{
    use Notifiable;

    protected $guarded = [];

    protected $casts = [
        'status' => SigningPartyStatus::class,
        'signing_round' => 'integer',
        'reminder_count' => 'integer',
        'signature_data' => 'encrypted:json',
        'signed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'viewed_at' => 'datetime',
        'notified_at' => 'datetime',
        'token_expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $party) {
            $party->uuid ??= Str::uuid()->toString();
            $party->signing_token ??= Str::random(64);
        });
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(SigningEnvelope::class, 'signing_envelope_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SigningEvent::class);
    }

    public function isTokenValid(): bool
    {
        if ($this->status === SigningPartyStatus::Signed || $this->status === SigningPartyStatus::Rejected) {
            return false;
        }

        if ($this->token_expires_at && $this->token_expires_at->isPast()) {
            return false;
        }

        $envelope = $this->envelope;
        if ($envelope->expires_at && $envelope->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
