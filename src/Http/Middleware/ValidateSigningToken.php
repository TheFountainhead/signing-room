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

        // Allow signed/rejected parties to view the page in read-only mode
        if ($party->status === \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Signed
            || $party->status === \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Rejected) {
            return $next($request);
        }

        if (! $party->isTokenValid()) {
            abort(410, 'Dette signing-link er ikke længere gyldigt.');
        }

        return $next($request);
    }
}
