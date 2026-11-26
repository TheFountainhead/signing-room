<?php

namespace Fountainhead\SigningRoom\Exceptions;

use RuntimeException;

/**
 * En kuvert kan ikke fuldføres, fordi en kopimodtager fra før 25/11-2026 er
 * åben som SIGNER hos Idura. Kræver manuel handling; beskeden siger hvilken.
 */
class EnvelopeBlockedByViewerException extends RuntimeException
{
}
