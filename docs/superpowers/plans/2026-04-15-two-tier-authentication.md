# Two-Tier Authentication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the insecure email-only login with two-tier auth: token-based signing links (pre-signing) and MitID login via Criipto Verify (post-signing).

**Architecture:** The signing-room Laravel package gets three changes: (1) signing links include the signing_token in the URL, scoped to one document only; (2) a new Criipto Verify OAuth flow for MitID login that matches on CPR hash; (3) CPR is fetched from Idura evidence API after signing and stored encrypted with a SHA-256 hash for lookup.

**Tech Stack:** Laravel 12, Livewire 3, Criipto Verify (OpenID Connect via raw OAuth2), Idura Signatures GraphQL API

**Spec:** `docs/superpowers/specs/2026-04-15-two-tier-authentication-design.md`

**Codebase:** `/Users/Frederik/Dropbox/Trustee/signing-room`

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `database/migrations/2026_04_15_000001_add_cpr_columns_to_signing_parties.php` | Create | Add `cpr_encrypted` and `cpr_hash` columns |
| `src/Models/SigningParty.php` | Modify | Add casts, CPR Eloquent mutator, `signingUrl()` method |
| `config/signing-room.php` | Modify | Add `criipto_verify` config section |
| `src/Services/IduraSignatureService.php` | Modify | Add `getSignatoryEvidence()` GraphQL query |
| `src/Services/SigningRoomService.php` | Modify | Fetch and store CPR in `handleSigned()` |
| `src/Http/Controllers/CriiptoAuthController.php` | Create | OAuth redirect + callback + CPR matching |
| `src/Http/Middleware/ValidateSigningToken.php` | Modify | Validate token from URL query parameter |
| `src/Livewire/Portal/SignDocument.php` | Modify | Remove email session leak |
| `src/Livewire/Portal/Landing.php` | Modify | Replace email login with MitID button |
| `resources/views/portal/landing.blade.php` | Modify | New layout with MitID + info text |
| `src/Livewire/Portal/Dashboard.php` | Modify | Require CPR session, query via `cpr_hash` |
| `src/Notifications/DocumentReadyNotification.php` | Modify | Add token to signing link |
| `src/Notifications/SigningReminderNotification.php` | Modify | Add token to signing link |
| `routes/portal.php` | Modify | Add auth routes, update download/PDF access checks |
| `src/SigningRoomServiceProvider.php` | No change | Routes loaded via existing `portal.php` |
| `composer.json` | No change | Raw OAuth2 — no new dependencies needed |

---

### Task 1: Migration — Add CPR columns to signing_parties

**Files:**
- Create: `database/migrations/2026_04_15_000001_add_cpr_columns_to_signing_parties.php`

- [ ] **Step 1: Create the migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_parties', function (Blueprint $table) {
            $table->text('cpr_encrypted')->nullable()->after('cpr_last_four');
            $table->string('cpr_hash', 64)->nullable()->after('cpr_encrypted');

            $table->index('cpr_hash');
        });
    }

    public function down(): void
    {
        Schema::table('signing_parties', function (Blueprint $table) {
            $table->dropIndex(['cpr_hash']);
            $table->dropColumn(['cpr_encrypted', 'cpr_hash']);
        });
    }
};
```

- [ ] **Step 2: Commit**

```bash
git add database/migrations/2026_04_15_000001_add_cpr_columns_to_signing_parties.php
git commit -m "feat: add cpr_encrypted and cpr_hash columns to signing_parties"
```

---

### Task 2: SigningParty model — CPR mutator, signingUrl(), casts

**Files:**
- Modify: `src/Models/SigningParty.php`

- [ ] **Step 1: Add casts, Eloquent CPR mutator, and signingUrl()**

Add `use Illuminate\Database\Eloquent\Casts\Attribute;` to imports.

Add `cpr_encrypted` to the `$casts` array:

```php
protected $casts = [
    'status' => SigningPartyStatus::class,
    'signing_round' => 'integer',
    'reminder_count' => 'integer',
    'signature_data' => 'encrypted:json',
    'cpr_encrypted' => 'encrypted',
    'signed_at' => 'datetime',
    'rejected_at' => 'datetime',
    'viewed_at' => 'datetime',
    'notified_at' => 'datetime',
    'token_expires_at' => 'datetime',
];
```

Add an Eloquent mutator that keeps `cpr_encrypted` and `cpr_hash` in sync:

```php
protected function cpr(): Attribute
{
    return Attribute::make(
        set: fn (string $value) => [
            'cpr_encrypted' => $value,
            'cpr_hash' => hash('sha256', $value),
        ],
    );
}
```

Add a `signingUrl()` method (single source of truth for token URLs):

```php
public function signingUrl(): string
{
    return route('signing-room.portal.sign', $this->uuid)
        . '?' . http_build_query(['token' => $this->signing_token]);
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Models/SigningParty.php
git commit -m "feat: add CPR mutator, signingUrl(), and cpr_encrypted cast to SigningParty"
```

---

### Task 3: Config — Add Criipto Verify section

**Files:**
- Modify: `config/signing-room.php`

- [ ] **Step 1: Add criipto_verify config block**

Add this after the `idura` block in the config array:

```php
'criipto_verify' => [
    'client_id' => env('CRIIPTO_VERIFY_CLIENT_ID'),
    'client_secret' => env('CRIIPTO_VERIFY_CLIENT_SECRET'),
    'domain' => env('CRIIPTO_VERIFY_DOMAIN'),
],
```

- [ ] **Step 2: Commit**

```bash
git add config/signing-room.php
git commit -m "feat: add criipto_verify config section for MitID login"
```

---

### Task 4: Idura evidence query — Fetch CPR after signing

**Files:**
- Modify: `src/Services/IduraSignatureService.php`

- [ ] **Step 1: Add getSignatoryEvidence() method**

Add this method to `IduraSignatureService`:

```php
/**
 * Fetch signatory evidence (identity claims) after signing.
 *
 * Returns the full CPR number from MitID evidence, or null if unavailable.
 */
public function getSignatoryEvidence(string $signatoryId): ?string
{
    $query = <<<'GRAPHQL'
query GetSignatoryEvidence($signatoryId: ID!) {
    signatory(id: $signatoryId) {
        evidence {
            ... on CriiptoVerifySignatureEvidence {
                claims
            }
        }
    }
}
GRAPHQL;

    try {
        $result = $this->query($query, ['signatoryId' => $signatoryId]);

        $evidence = $result['signatory']['evidence'] ?? [];

        foreach ($evidence as $item) {
            $cpr = $item['claims']['cprNumberIdentifier'] ?? null;
            if ($cpr) {
                return $cpr;
            }
        }
    } catch (\RuntimeException $e) {
        report($e);
    }

    return null;
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Services/IduraSignatureService.php
git commit -m "feat: add getSignatoryEvidence() to fetch CPR from Idura after signing"
```

---

### Task 5: Store CPR in handleSigned webhook handler

**Files:**
- Modify: `src/Services/SigningRoomService.php`

- [ ] **Step 1: Update handleSigned() to fetch and store CPR**

In `handleSigned()`, after the existing `$party->update(...)` call (line 139-143), add CPR fetching:

```php
public function handleSigned(SigningParty $party, ?array $signatureData = null): void
{
    if ($party->status === SigningPartyStatus::Signed) {
        return;
    }

    $party->update([
        'status' => SigningPartyStatus::Signed,
        'signed_at' => now(),
        'signature_data' => $signatureData,
    ]);

    // Fetch and store CPR from Idura evidence
    if ($party->idura_signatory_id) {
        $cpr = $this->idura->getSignatoryEvidence($party->idura_signatory_id);
        if ($cpr) {
            $party->cpr = $cpr;
            $party->save();
        }
    }

    $envelope = $party->envelope;

    $envelope->logEvent(SigningEventType::PartySigned, $party);

    PartySignedEvent::dispatch($envelope, $party);

    if ($envelope->isAllRoundsComplete()) {
        $this->completeEnvelope($envelope);
    } elseif ($envelope->isCurrentRoundComplete()) {
        $this->advanceRound($envelope);
    } else {
        $envelope->update([
            'status' => EnvelopeStatus::PartiallySigned,
        ]);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Services/SigningRoomService.php
git commit -m "feat: fetch and store CPR evidence after MitID signing"
```

---

### Task 6: Token in URL — Update signing link notifications

**Files:**
- Modify: `src/Notifications/DocumentReadyNotification.php`
- Modify: `src/Notifications/SigningReminderNotification.php`

- [ ] **Step 1: Use signingUrl() in DocumentReadyNotification**

In `DocumentReadyNotification.php`, change the `$signingUrl` line in `toMail()`:

```php
public function toMail($notifiable): MailMessage
{
    $signingUrl = $notifiable->signingUrl();

    // ... rest unchanged
}
```

- [ ] **Step 2: Use signingUrl() in SigningReminderNotification**

In `SigningReminderNotification.php`, change the `$signingUrl` line in `toMail()`:

```php
public function toMail($notifiable): MailMessage
{
    $signingUrl = $notifiable->signingUrl();

    // ... rest unchanged
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Notifications/DocumentReadyNotification.php src/Notifications/SigningReminderNotification.php
git commit -m "feat: include signing_token in notification email URLs"
```

---

### Task 7: Validate token from URL query parameter

**Files:**
- Modify: `src/Http/Middleware/ValidateSigningToken.php`

- [ ] **Step 1: Update middleware to require token in URL**

Replace the entire `ValidateSigningToken` middleware:

```php
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
```

- [ ] **Step 2: Commit**

```bash
git add src/Http/Middleware/ValidateSigningToken.php
git commit -m "feat: require signing_token in URL query parameter for document access"
```

---

### Task 8: Remove email session leak from SignDocument

**Files:**
- Modify: `src/Livewire/Portal/SignDocument.php`

- [ ] **Step 1: Remove the session line from mount()**

In `SignDocument.php`, remove line 24:

```php
// DELETE THIS LINE:
session(['signing_room_email' => $this->signingParty->email]);
```

The `mount()` method becomes:

```php
public function mount(SigningParty $signingParty): void
{
    $this->signingParty = $signingParty->load('envelope.parties');

    // Mark as viewed on first access (skip if already signed/rejected)
    if (! $this->signingParty->viewed_at
        && $this->signingParty->status !== SigningPartyStatus::Signed
        && $this->signingParty->status !== SigningPartyStatus::Rejected) {
        $this->signingParty->update([
            'viewed_at' => now(),
            'status' => SigningPartyStatus::Viewed,
        ]);

        $this->signingParty->envelope->logEvent(
            SigningEventType::PartyViewed,
            $this->signingParty,
        );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Livewire/Portal/SignDocument.php
git commit -m "fix: remove email session leak from SignDocument mount"
```

---

### Task 9: Criipto Verify OAuth controller

**Files:**
- Create: `src/Http/Controllers/CriiptoAuthController.php`

**Review fixes applied:**
- OAuth `state` parameter stored in session and validated in callback (CSRF protection)
- JWT `iss` and `aud` claims verified after decoding
- Rate limiting added via `throttle` middleware (see Task 10)
- Legacy `signing_room_email` session cleanup removed (YAGNI — expires naturally)

- [ ] **Step 1: Create CriiptoAuthController**

```php
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
            'redirect_uri' => route('signing-room.auth.callback'),
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
            'redirect_uri' => route('signing-room.auth.callback'),
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

        if (! $hasDocuments) {
            return redirect()->route('signing-room.portal.landing')
                ->with('error', 'Vi fandt ingen dokumenter tilknyttet dit MitID.');
        }

        // Set CPR session for dashboard access
        session(['signing_room_cpr' => $cprHash]);

        return redirect()->route('signing-room.portal.dashboard');
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
```

- [ ] **Step 2: Commit**

```bash
git add src/Http/Controllers/CriiptoAuthController.php
git commit -m "feat: add CriiptoAuthController for MitID login via OAuth"
```

---

### Task 10: Register auth routes

**Files:**
- Modify: `routes/portal.php`

- [ ] **Step 1: Add auth routes and update download/PDF access checks**

Add auth routes at the top of the route group (before existing routes). Replace the download and PDF closures' session checks. Replace the sign-out route.

Add these three routes inside the existing `Route::middleware(...)...->group(function () {` block, before the existing `Route::get('/')` line:

```php
// MitID auth routes (throttled to prevent Criipto quota abuse)
Route::middleware('throttle:10,1')->group(function () {
    Route::get('/auth/criipto', [\Fountainhead\SigningRoom\Http\Controllers\CriiptoAuthController::class, 'redirect'])
        ->name('auth.redirect');

    Route::get('/auth/criipto/callback', [\Fountainhead\SigningRoom\Http\Controllers\CriiptoAuthController::class, 'callback'])
        ->name('auth.callback');
});
```

Replace the existing `POST /sign-out` route with:

```php
Route::post('/sign-out', [\Fountainhead\SigningRoom\Http\Controllers\CriiptoAuthController::class, 'logout'])
    ->name('logout');
```

Update the download closure — replace the `$email`/`$hasPortalSession` logic:

```php
Route::get('/download/{signingEnvelope:uuid}', function (SigningEnvelope $signingEnvelope) {
    // Allow access via CPR session OR authenticated admin user
    $cprHash = session('signing_room_cpr');
    $hasPortalSession = $cprHash && $signingEnvelope->parties()->where('cpr_hash', $cprHash)->exists();
    $hasAuthSession = auth()->check() && $signingEnvelope->parties()->where('email', auth()->user()->email)->exists();

    if (! $hasPortalSession && ! $hasAuthSession) {
        abort(403, 'Du har ikke adgang til dette dokument.');
    }

    if (! $signingEnvelope->signed_document) {
        abort(404, 'Det signerede dokument er ikke tilgængeligt endnu.');
    }

    $disk = Storage::disk(config('signing-room.storage.disk', 'local'));
    $filename = str($signingEnvelope->title)->slug() . '-signeret.pdf';

    return $disk->download($signingEnvelope->signed_document, $filename);
})->name('download');
```

Update the PDF inline view closure — replace the `$email`/`$hasSession` logic:

```php
Route::get('/pdf/{signingParty:uuid}', function (SigningParty $signingParty) {
    // Allow access if: CPR session matches OR signing token is valid
    $cprHash = session('signing_room_cpr');
    $hasCprSession = $cprHash && $signingParty->cpr_hash === $cprHash;

    $token = request()->query('token');
    $hasValidToken = $token && hash_equals($signingParty->signing_token, $token);

    if (! $hasCprSession && ! $hasValidToken) {
        abort(403);
    }

    $envelope = $signingParty->envelope;
    $document = $envelope->signed_document ?? $envelope->original_document;

    if (! $document) {
        abort(404);
    }

    $disk = Storage::disk(config('signing-room.storage.disk', 'local'));

    return response($disk->get($document), 200, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'inline',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Content-Security-Policy' => "frame-ancestors 'self'",
    ]);
})->name('pdf');
```

- [ ] **Step 2: Commit**

```bash
git add routes/portal.php
git commit -m "feat: add MitID auth routes, update download/PDF access to use CPR session"
```

---

### Task 11: Update Dashboard to use CPR session

**Files:**
- Modify: `src/Livewire/Portal/Dashboard.php`

- [ ] **Step 1: Replace email session with CPR session**

Replace the entire `Dashboard.php`:

```php
<?php

namespace Fountainhead\SigningRoom\Livewire\Portal;

use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
use Fountainhead\SigningRoom\Models\SigningParty;
use Livewire\Component;

class Dashboard extends Component
{
    public function mount(): void
    {
        if (! session('signing_room_cpr')) {
            $this->redirect(route('signing-room.portal.landing'));
        }
    }

    public function render()
    {
        $cprHash = session('signing_room_cpr');

        $parties = SigningParty::where('cpr_hash', $cprHash)
            ->with('envelope')
            ->latest()
            ->get();

        $pending = $parties->filter(fn ($p) => in_array($p->status, [
            SigningPartyStatus::Pending,
            SigningPartyStatus::Notified,
            SigningPartyStatus::Viewed,
        ]));

        $signed = $parties->filter(fn ($p) => $p->status === SigningPartyStatus::Signed);

        $other = $parties->filter(fn ($p) => in_array($p->status, [
            SigningPartyStatus::Rejected,
            SigningPartyStatus::Error,
        ]));

        return view('signing-room::portal.dashboard', [
            'pending' => $pending,
            'signed' => $signed,
            'other' => $other,
        ])->layout('signing-room::layouts.portal', ['title' => 'Mine dokumenter']);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Livewire/Portal/Dashboard.php
git commit -m "feat: dashboard requires MitID CPR session, queries via cpr_hash"
```

---

### Task 12: Replace Landing page — MitID button + info text

**Files:**
- Modify: `src/Livewire/Portal/Landing.php`
- Modify: `resources/views/portal/landing.blade.php`

- [ ] **Step 1: Simplify Landing.php — remove email login logic**

Replace the entire `Landing.php`:

```php
<?php

namespace Fountainhead\SigningRoom\Livewire\Portal;

use Livewire\Component;

class Landing extends Component
{
    public function render()
    {
        $hasCriiptoVerify = (bool) config('signing-room.criipto_verify.client_id');

        return view('signing-room::portal.landing', [
            'hasCriiptoVerify' => $hasCriiptoVerify,
            'error' => session('error'),
        ])->layout('signing-room::layouts.portal', ['title' => 'Underskriftrum']);
    }
}
```

- [ ] **Step 2: Replace landing.blade.php with MitID layout**

Replace the email form section (the `<div style="padding: 48px; text-align: center;">` block inside the card, lines 46-88) with:

```blade
            <div style="padding: 48px; text-align: center;">
                <h1 style="margin-bottom: 16px;">Underskriftrum</h1>
                <p style="color: var(--ft-dark); margin-bottom: 32px; font-size: 1.125rem; line-height: 1.7;">
                    Her kan du underskrive dokumenter sikkert med MitID.
                </p>

                <div style="max-width: 440px; margin: 0 auto;">
                    {{-- Info: email link --}}
                    <div style="padding: 20px 24px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; margin-bottom: 16px; text-align: left;">
                        <p style="font-weight: 600; color: var(--ft-dark); margin: 0 0 4px; font-size: 1rem;">
                            Har du modtaget et link?
                        </p>
                        <p style="color: var(--ft-grey); margin: 0; font-size: 0.95rem;">
                            Klik på linket i din e-mail for at se og underskrive dit dokument.
                        </p>
                    </div>

                    {{-- MitID login --}}
                    <div style="padding: 20px 24px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; text-align: left;">
                        <p style="font-weight: 600; color: var(--ft-dark); margin: 0 0 8px; font-size: 1rem;">
                            Har du allerede underskrevet?
                        </p>
                        <p style="color: var(--ft-grey); margin: 0 0 16px; font-size: 0.95rem;">
                            Log ind med MitID for at se dine dokumenter.
                        </p>

                        @if($hasCriiptoVerify)
                            <a href="{{ route('signing-room.auth.redirect') }}"
                               class="btn-primary"
                               style="display: inline-block; font-size: 1.05rem; padding: 14px 32px; text-decoration: none; text-align: center; width: 100%; box-sizing: border-box;">
                                Log ind med MitID
                            </a>
                        @endif
                    </div>

                    @if($error)
                        <div style="margin-top: 16px; padding: 12px 16px; background: #FFF8E1; border: 1px solid #FFE082; border-radius: 6px;">
                            <p style="color: #F57F17; font-size: 0.9rem; font-weight: 600; margin: 0;">
                                {{ $error }}
                            </p>
                        </div>
                    @endif
                </div>

                <div style="margin-top: 24px; font-size: 0.875rem; color: var(--ft-grey);">
                    <p>Sikker digital underskrift med MitID</p>
                </div>
            </div>
```

- [ ] **Step 3: Commit**

```bash
git add src/Livewire/Portal/Landing.php resources/views/portal/landing.blade.php
git commit -m "feat: replace email login with MitID button and info text on landing page"
```

---

### Task 13: Update sign-document view — PDF links and navigation

**Files:**
- Modify: `resources/views/portal/sign-document.blade.php`

- [ ] **Step 1: Remove "Tilbage til mine dokumenter" link**

The signing link only grants access to one document, not the dashboard. Remove the back-link at line 4:

```blade
{{-- DELETE this link: --}}
<a href="{{ route('signing-room.portal.dashboard') }}" style="...">
    &larr; Tilbage til mine dokumenter
</a>
```

- [ ] **Step 2: Add token to PDF URLs**

The PDF links need the signing token appended. There are 3 occurrences in the file. Replace all instances of:

```blade
route('signing-room.portal.pdf', $signingParty)
```

with:

```blade
route('signing-room.portal.pdf', $signingParty) . '?' . http_build_query(['token' => $signingParty->signing_token])
```

This applies to:
- Line 26: "Åbn i nyt vindue" link (`href` attribute)
- Line 46: "Se dokument" fallback button (`href` attribute)
- Line 54: JavaScript `pdfUrl` variable (`@json(...)` — change to `@json(route(...) . '?' . http_build_query(['token' => $signingParty->signing_token]))`)

- [ ] **Step 3: Commit**

```bash
git add resources/views/portal/sign-document.blade.php
git commit -m "fix: add token to PDF URLs and remove dashboard back-link from sign document view"
```

---

### Task 14: Smoke test — Verify all routes and access controls

- [ ] **Step 1: Verify the package loads without errors**

In the host app directory, run:

```bash
php artisan route:list --name=signing-room
```

Expected: all routes listed including new `signing-room.auth.redirect`, `signing-room.auth.callback`, and updated `signing-room.portal.logout`.

- [ ] **Step 2: Run migration**

```bash
php artisan migrate
```

Expected: `cpr_encrypted` and `cpr_hash` columns added to `signing_parties`.

- [ ] **Step 3: Verify landing page**

Visit `https://sign.frankston.io` in a browser.

Expected: No email field. Two info boxes: "Har du modtaget et link?" and "Har du allerede underskrevet?" with MitID button.

- [ ] **Step 4: Test signing link without token**

Visit `https://sign.frankston.io/sign/{some-uuid}` (no `?token=` parameter).

Expected: 403 — "Ugyldigt eller manglende signing-token."

- [ ] **Step 5: Test signing link with valid token**

Visit `https://sign.frankston.io/sign/{uuid}?token={signing_token}` using a real party's token.

Expected: Document displayed. No email session set. Dashboard NOT accessible.

- [ ] **Step 6: Test MitID login flow**

Click "Log ind med MitID" on landing page.

Expected: Redirect to Criipto → MitID → callback → dashboard (if CPR matches) or error message (if no documents).

- [ ] **Step 7: Test dashboard requires MitID**

Visit `https://sign.frankston.io/dashboard` directly without MitID login.

Expected: Redirect to landing page.

- [ ] **Step 8: Final commit if any fixes needed**

```bash
git add -A
git commit -m "fix: smoke test adjustments for two-tier auth"
```
