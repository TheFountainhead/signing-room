# Two-Tier Authentication for Signing Room

**Date:** 2026-04-15
**Status:** Draft
**Package:** `fountainhead/signing-room`

## Problem

The current portal authentication accepts any email address and displays all documents for that email. There is no verification of email ownership. Anyone who knows or guesses an email address can view document titles, statuses, deadlines, and access PDFs.

## Solution: Two-Tier Authentication

Replace email-only login with a two-tier model:

- **Tier 1 (Pre-signing):** Access via token link from notification email. One link = one document.
- **Tier 2 (Post-signing):** MitID login via Criipto Verify. CPR match = all own documents.

The public email login is removed entirely.

## Access Model

| User type | Access via | Sees |
|---|---|---|
| New signer | Email link with token (`/sign/{uuid}?token=xxx`) | Only that one document |
| Returning user | MitID login on landing page | All own documents (matched on CPR) |
| Unauthorized | Nothing — email login removed | Landing page with MitID button + info |

### Session Model

- Signing link: does NOT set an email session. Access scoped to the single document only.
- MitID login: sets `signing_room_cpr` session. Grants access to dashboard with all documents matching that CPR.
- Dashboard requires MitID session. No other path in.

## CPR Storage and Matching

### At signing time (Idura webhook)

1. `SIGNATORY_SIGNED` webhook fires
2. Query Idura GraphQL API for signatory evidence (new `getSignatoryEvidence()` method)
3. Extract full CPR from `cprNumberIdentifier` claim
4. Store as `cpr_encrypted` (Laravel `encrypted` cast) on `SigningParty`
5. Store SHA-256 hash as `cpr_hash` for indexed lookup
6. Existing `cpr_last_four` field remains unchanged

### At MitID login

1. User clicks "Log ind med MitID" on landing page
2. Redirect to Criipto Verify OAuth (OpenID Connect)
3. Criipto returns JWT with `cprNumberIdentifier`
4. Compute SHA-256 hash of CPR
5. Query `SigningParty::where('cpr_hash', $hash)` for all matching parties
6. Set session `signing_room_cpr` with hashed value
7. Display dashboard with all matching documents

## Criipto Verify Configuration

- **App name:** FrankSign Login
- **Product:** Verify
- **Domain:** `sequii.mitid.dk`
- **Client ID:** `urn:my:application:identifier:214179`
- **Callback URL:** `https://sign.frankston.io/auth/criipto/callback`
- **Dynamic scopes:** Disabled (required for `cprNumberIdentifier` in JWT)

Environment variables for host app:
```
CRIIPTO_VERIFY_CLIENT_ID=urn:my:application:identifier:214179
CRIIPTO_VERIFY_CLIENT_SECRET=<secret>
CRIIPTO_VERIFY_DOMAIN=sequii.mitid.dk
```

## Database Changes

New fields on `signing_parties` table:

| Field | Type | Details |
|---|---|---|
| `cpr_encrypted` | `text`, nullable | Full CPR, Laravel `encrypted` cast |
| `cpr_hash` | `string(64)`, nullable, indexed | SHA-256 of CPR for lookup |

## New Routes

```
GET  /auth/criipto           → Redirect to Criipto Verify OAuth
GET  /auth/criipto/callback  → Handle callback, match CPR, set session
POST /auth/logout            → Clear session (replaces current sign-out)
```

## New Files

- `CriiptoAuthController.php` — OAuth redirect, callback handling, CPR matching
- Migration: add `cpr_encrypted` + `cpr_hash` columns

## Changed Files

- `portal.php` routes (download/pdf closures) — replace `session('signing_room_email')` checks with `signing_room_cpr` session OR valid token. Download requires MitID session with matching CPR or authenticated admin.
- `IduraSignatureService.php` — new `getSignatoryEvidence()` GraphQL query
- `SigningRoomService.php` → `handleSigned()` — fetch evidence, store CPR
- `SigningParty.php` — new casts, `cpr_hash` generation
- `Landing.php` — remove email login, show MitID button + info text
- `landing.blade.php` — new layout with two-path UX
- `Dashboard.php` — require `signing_room_cpr` session, query via `cpr_hash`
- `SignDocument.php` — remove `session(['signing_room_email' => ...])` from mount
- `ValidateSigningToken.php` — validate token from URL query parameter
- `DocumentReadyNotification.php` — add token to signing link URL
- `SigningReminderNotification.php` — add token to reminder link URL
- `portal.php` routes — add token parameter, new auth routes

## Removed

- Email login flow in `Landing.php`
- `session('signing_room_email')` as access mechanism
- Dashboard access via signing link (session leak)

## Config Addition

New section in `config/signing-room.php`:

```php
'criipto_verify' => [
    'client_id' => env('CRIIPTO_VERIFY_CLIENT_ID'),
    'client_secret' => env('CRIIPTO_VERIFY_CLIENT_SECRET'),
    'domain' => env('CRIIPTO_VERIFY_DOMAIN'),
    'redirect_uri' => env('CRIIPTO_VERIFY_REDIRECT_URI', '/auth/criipto/callback'),
],
```

## Edge Cases

| Scenario | Handling |
|---|---|
| MitID login, no documents found | Show "Vi fandt ingen dokumenter tilknyttet dit MitID" |
| Signing link with invalid/expired token | 410 Gone — "Dette link er udlobet" |
| CPR evidence fetch fails from Idura | Log error, store `null`. Signing succeeds but MitID login won't find this document later |
| Multiple SigningParties with same CPR | All shown in dashboard (correct — same person, multiple documents) |
| Criipto Verify is down | Error message on landing page. Signing links still work independently |

## Landing Page UX

```
+---------------------------------------+
|         Underskriftrum                |
|                                       |
|  Har du modtaget et link?             |
|  Klik pa linket i din email.          |
|                                       |
|  Har du allerede underskrevet?        |
|  [  Log ind med MitID  ]             |
|                                       |
|  Sikker digital underskrift med MitID |
+---------------------------------------+
```

## Acceptance Criteria

1. Email login is removed. Entering an email address is no longer possible.
2. Signing links include token in URL. Invalid/expired tokens return 410.
3. Signing links grant access to only the specific document, not the dashboard.
4. After MitID signing, full CPR is fetched from Idura and stored encrypted with a SHA-256 hash.
5. MitID login via Criipto Verify is available on the landing page.
6. MitID login matches CPR hash and displays all documents where the user is a signing party.
7. Users with no matching documents see an appropriate message.
8. All authentication logic is contained within the signing-room package.
9. Host app only needs to set environment variables for Criipto Verify credentials.
