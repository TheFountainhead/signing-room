<?php

namespace Fountainhead\SigningRoom\Enums;

enum SigningPartyStatus: string
{
    case Pending = 'pending';
    case Notified = 'notified';
    case Viewed = 'viewed';
    case Signed = 'signed';
    case Rejected = 'rejected';
    case Error = 'error';
}
