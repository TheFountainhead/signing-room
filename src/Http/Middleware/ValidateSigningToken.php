<?php

namespace Fountainhead\SigningRoom\Http\Middleware;

use Closure;
use Fountainhead\SigningRoom\Models\SigningParty;
use Illuminate\Http\Request;

class ValidateSigningToken
{
    public function handle(Request $request, Closure $next)
    {
        $party = $request->route('signingParty');

        if (! $party instanceof SigningParty) {
            abort(404);
        }

        if (! $party->isTokenValid()) {
            abort(410, 'Dette signing-link er ikke længere gyldigt.');
        }

        return $next($request);
    }
}
