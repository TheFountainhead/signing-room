<?php

namespace Fountainhead\SigningRoom\Exceptions;

use InvalidArgumentException;

/**
 * En kuvert uden underskrivere kan aldrig underskrives og afvises derfor i
 * createEnvelope og sendEnvelope. Udvider InvalidArgumentException, så hosts
 * der allerede fanger den, virker uændret.
 */
class EnvelopeHasNoSignerException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('En kuvert skal have mindst én underskriver.');
    }
}
