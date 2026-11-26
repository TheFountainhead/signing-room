<?php

namespace Fountainhead\SigningRoom\Exceptions;

use RuntimeException;

/**
 * Ordren er lukket hos Idura, men den signerede PDF kan ikke hentes.
 *
 * Kritisk: Idura sletter dokumenterne retain_documents dage efter lukning, og
 * vi har ingen kopi. Kræver manuel handling med det samme.
 */
class SignedDocumentUnavailableException extends RuntimeException
{
}
