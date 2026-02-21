<?php

namespace Fountainhead\SigningRoom\Enums;

enum SigningEventType: string
{
    case EnvelopeCreated = 'envelope.created';
    case EnvelopeSent = 'envelope.sent';
    case EnvelopeCompleted = 'envelope.completed';
    case EnvelopeExpired = 'envelope.expired';
    case EnvelopeCancelled = 'envelope.cancelled';
    case PartyNotified = 'party.notified';
    case PartyReminded = 'party.reminded';
    case PartyViewed = 'party.viewed';
    case PartySigned = 'party.signed';
    case PartyRejected = 'party.rejected';
    case PartyError = 'party.error';
    case RoundAdvanced = 'round.advanced';
}
