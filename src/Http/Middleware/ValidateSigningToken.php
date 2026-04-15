<?php

namespace Fountainhead\SigningRoom\Http\Middleware;

use Closure;
use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
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

        // Allow signed/rejected parties to view in read-only mode (with valid token)
        $isSigned = $party->status === SigningPartyStatus::Signed;
        $isRejected = $party->status === SigningPartyStatus::Rejected;

        // Validate token from URL query parameter
        $token = $request->query('token');

        if (! $token || ! hash_equals($party->signing_token, $token)) {
            abort(403, 'Ugyldigt eller manglende signing-token.');
        }

        // For non-completed parties, also check token expiry
        if (! $isSigned && ! $isRejected && ! $party->isTokenValid()) {
            abort(410, 'Dette signing-link er ikke længere gyldigt.');
        }

        return $next($request);
    }
}
