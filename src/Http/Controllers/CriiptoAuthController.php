<?php

namespace Fountainhead\SigningRoom\Http\Controllers;

use Fountainhead\SigningRoom\Models\SigningParty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CriiptoAuthController extends Controller
{
    /**
     * Redirect to Criipto Verify for MitID login.
     */
    public function redirect(): RedirectResponse
    {
        $config = config('signing-room.criipto_verify');

        // Generate and store state for CSRF validation
        $state = Str::random(40);
        session(['signing_room_oauth_state' => $state]);

        $params = [
            'response_type' => 'code',
            'client_id' => $config['client_id'],
            'redirect_uri' => route('signing-room.portal.auth.callback'),
            'scope' => 'openid',
            'acr_values' => 'urn:grn:authn:dk:mitid:low',
            'state' => $state,
        ];

        $authorizeUrl = 'https://' . $config['domain'] . '/oauth2/authorize?' . http_build_query($params);

        return redirect()->away($authorizeUrl);
    }

    /**
     * Handle Criipto Verify OAuth callback.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            return redirect()->route('signing-room.portal.landing')
                ->with('error', 'MitID login mislykkedes. Prøv igen.');
        }

        // Validate OAuth state parameter (CSRF protection)
        $expectedState = session()->pull('signing_room_oauth_state');
        if (! $expectedState || $request->query('state') !== $expectedState) {
            return redirect()->route('signing-room.portal.landing')
                ->with('error', 'Ugyldig session. Prøv igen.');
        }

        $code = $request->query('code');
        if (! $code) {
            return redirect()->route('signing-room.portal.landing')
                ->with('error', 'Ugyldigt svar fra MitID.');
        }

        $config = config('signing-room.criipto_verify');

        // Exchange code for token
        $tokenResponse = Http::asForm()->post('https://' . $config['domain'] . '/oauth2/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => route('signing-room.portal.auth.callback'),
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
        ]);

        if ($tokenResponse->failed()) {
            return redirect()->route('signing-room.portal.landing')
                ->with('error', 'Kunne ikke verificere MitID login.');
        }

        $idToken = $tokenResponse->json('id_token');
        if (! $idToken) {
            return redirect()->route('signing-room.portal.landing')
                ->with('error', 'Intet ID-token modtaget fra MitID.');
        }

        // Decode and validate JWT payload
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return redirect()->route('signing-room.portal.landing')
                ->with('error', 'Ugyldigt token fra MitID.');
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        if (! is_array($payload)) {
            return redirect()->route('signing-room.portal.landing')
                ->with('error', 'Ugyldigt token fra MitID.');
        }

        // Verify issuer and audience
        $expectedIssuer = 'https://' . $config['domain'];
        if (($payload['iss'] ?? '') !== $expectedIssuer || ($payload['aud'] ?? '') !== $config['client_id']) {
            return redirect()->route('signing-room.portal.landing')
                ->with('error', 'Token-validering fejlede.');
        }

        $cpr = $payload['cprNumberIdentifier'] ?? null;

        if (! $cpr) {
            return redirect()->route('signing-room.portal.landing')
                ->with('error', 'CPR-nummer ikke tilgængeligt fra MitID.');
        }

        $cprHash = hash('sha256', $cpr);

        // Check if any signing parties match this CPR
        $hasDocuments = SigningParty::where('cpr_hash', $cprHash)->exists();

        // Backfill: if no CPR match, try matching legacy parties without cpr_hash
        if (! $hasDocuments) {
            // Try cpr_last_four first
            $lastFour = substr($cpr, -4);
            $partiesWithoutCpr = SigningParty::whereNull('cpr_hash')
                ->where(function ($query) use ($lastFour) {
                    $query->where('cpr_last_four', $lastFour);
                })
                ->get();

            // If no cpr_last_four match, store CPR for email verification step
            if ($partiesWithoutCpr->isEmpty()) {
                session(['signing_room_pending_cpr' => base64_encode($cpr)]);
                session(['signing_room_pending_cpr_hash' => $cprHash]);

                return redirect()->route('signing-room.portal.landing')
                    ->with('verify_email', true);
            }

            foreach ($partiesWithoutCpr as $party) {
                $party->cpr = $cpr;
                $party->save();
            }

            $hasDocuments = true;
        }

        if (! $hasDocuments) {
            return redirect()->route('signing-room.portal.landing')
                ->with('error', 'Vi fandt ingen dokumenter tilknyttet dit MitID.');
        }

        // Set CPR session for dashboard access (regenerate to prevent session fixation)
        session()->regenerate();
        session(['signing_room_cpr' => $cprHash]);

        return redirect()->route('signing-room.portal.dashboard');
    }

    /**
     * Verify email to link legacy signing parties to CPR after MitID login.
     */
    public function verifyEmail(Request $request): RedirectResponse
    {
        try {
            $encryptedCpr = session('signing_room_pending_cpr');
            $cprHash = session('signing_room_pending_cpr_hash');

            if (! $encryptedCpr || ! $cprHash) {
                return redirect()->route('signing-room.portal.landing')
                    ->with('error', 'Session udløbet. Log ind med MitID igen.');
            }

            $email = $request->input('email');
            if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return redirect()->route('signing-room.portal.landing')
                    ->with('error', 'Indtast en gyldig e-mailadresse.')
                    ->with('verify_email', true);
            }

            $parties = SigningParty::where('email', $email)
                ->whereNull('cpr_hash')
                ->get();

            if ($parties->isEmpty()) {
                return redirect()->route('signing-room.portal.landing')
                    ->with('error', 'Vi fandt ingen dokumenter til denne e-mailadresse.');
            }

            // Backfill CPR for matched parties
            $cpr = base64_decode($encryptedCpr);
            foreach ($parties as $party) {
                $party->cpr = $cpr;
                $party->save();
            }

            session()->forget(['signing_room_pending_cpr', 'signing_room_pending_cpr_hash']);
            session()->regenerate();
            session(['signing_room_cpr' => $cprHash]);

            return redirect()->route('signing-room.portal.dashboard');
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('signing-room.portal.landing')
                ->with('error', 'Fejl i ' . basename($e->getFile()) . ':' . $e->getLine() . ' — ' . $e->getMessage());
        }
    }

    /**
     * Log out and clear session.
     */
    public function logout(): RedirectResponse
    {
        session()->forget('signing_room_cpr');

        return redirect()->route('signing-room.portal.landing');
    }
}
