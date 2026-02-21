<?php

namespace Fountainhead\SigningRoom\Enums;

enum EnvelopeStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case PartiallySigned = 'partially_signed';
    case Completed = 'completed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Kladde',
            self::Sent => 'Sendt',
            self::PartiallySigned => 'Delvist underskrevet',
            self::Completed => 'Færdig',
            self::Expired => 'Udløbet',
            self::Cancelled => 'Annulleret',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'blue',
            self::PartiallySigned => 'yellow',
            self::Completed => 'green',
            self::Expired => 'red',
            self::Cancelled => 'red',
        };
    }
}
