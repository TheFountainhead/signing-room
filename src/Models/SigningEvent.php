<?php

namespace Fountainhead\SigningRoom\Models;

use Fountainhead\SigningRoom\Enums\SigningEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SigningEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'event_type' => SigningEventType::class,
        'metadata' => 'json',
        'created_at' => 'datetime',
    ];

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(SigningEnvelope::class, 'signing_envelope_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(SigningParty::class, 'signing_party_id');
    }
}
