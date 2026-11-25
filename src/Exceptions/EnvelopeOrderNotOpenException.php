<?php

namespace Fountainhead\SigningRoom\Exceptions;

use RuntimeException;

/**
 * Alle har underskrevet, men Idura-ordren er CANCELLED eller EXPIRED, så
 * kuverten kan ikke fuldføres. Rapporteres én gang pr. kuvert via
 * SigningEnvelope::reportBlockedOnce(). Kræver manuel handling.
 */
class EnvelopeOrderNotOpenException extends RuntimeException
{
}
