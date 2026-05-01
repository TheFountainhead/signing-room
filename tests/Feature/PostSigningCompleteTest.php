<?php

namespace Fountainhead\SigningRoom\Tests\Feature;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Fountainhead\SigningRoom\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * FHT-1962: Peter Garnry (Gesda Capital) reported that after signing a document
 * via an emailed signing link, the post-signing page offered no path back to
 * his profile — the Frankston logo and "Mine dokumenter" button both appeared
 * to dump him on a near-identical-looking page.
 *
 * Root cause is two-tier: Tier 1 (token-link) signers have no
 * `signing_room_cpr` session, so /dashboard bounces them straight back to
 * landing. The "Mine dokumenter" button on /complete is therefore a trap.
 *
 * These tests lock the bilateral fix:
 *   - Tier 1 users (no CPR session) get a "Log ind med MitID" CTA pointing
 *     to the Criipto OAuth flow — the only path to their profile.
 *   - Tier 2 users (already MitID-logged-in) keep the direct "Mine dokumenter"
 *     button — the fix must not regress them.
 */
class PostSigningCompleteTest extends TestCase
{
    use RefreshDatabase;

    private function createEnvelope(array $attributes = []): SigningEnvelope
    {
        return SigningEnvelope::create(array_merge([
            'title'             => 'Test Aftale',
            'status'            => EnvelopeStatus::Sent,
            'original_document' => 'signing-room/test.pdf',
            'total_rounds'      => 1,
            'current_round'     => 1,
        ], $attributes));
    }

    private function createParty(SigningEnvelope $envelope, array $attributes = []): SigningParty
    {
        return SigningParty::create(array_merge([
            'signing_envelope_id' => $envelope->id,
            'name'                => 'Test Underskriver',
            'email'               => 'test@example.com',
            'status'              => SigningPartyStatus::Pending,
            'signing_round'       => 1,
        ], $attributes));
    }

    // -------------------------------------------------------------------------
    // Tier 1 (email-token signer, no CPR session): MitID login CTA
    // -------------------------------------------------------------------------

    /** @test */
    public function tier_1_user_sees_mitid_login_cta_on_complete_page(): void
    {
        $this->get(route('signing-room.portal.signing-complete'))
            ->assertOk()
            ->assertSee('Log ind med MitID')
            ->assertSee(route('signing-room.portal.auth.redirect'));
    }

    /**
     * @test
     *
     * Bilateral negative half — the dashboard link must NOT appear for Tier 1
     * users, because /dashboard bounces them back to /landing without a CPR
     * session, looping them back to where they started. Showing the link is
     * exactly the trap that produced FHT-1962.
     */
    public function tier_1_user_does_not_see_dashboard_link_on_complete_page(): void
    {
        $response = $this->get(route('signing-room.portal.signing-complete'))
            ->assertOk();

        $this->assertStringNotContainsString(
            route('signing-room.portal.dashboard'),
            $response->getContent(),
            'Tier 1 users (no CPR session) must not be offered "Mine dokumenter" '
            .'— the link bounces them back to landing and reproduces FHT-1962.'
        );
    }

    // -------------------------------------------------------------------------
    // Tier 2 (MitID-logged-in): direct dashboard CTA
    // -------------------------------------------------------------------------

    /** @test */
    public function tier_2_user_sees_dashboard_link_on_complete_page(): void
    {
        $cprHash = hash('sha256', '1234567890');

        $this->withSession(['signing_room_cpr' => $cprHash])
            ->get(route('signing-room.portal.signing-complete'))
            ->assertOk()
            ->assertSee(route('signing-room.portal.dashboard'))
            ->assertSee('Mine dokumenter');
    }

    /**
     * @test
     *
     * Bilateral negative half — Tier 2 users (already logged in) should not
     * be told to "Log ind med MitID" again; that would be a UX regression
     * for the logged-in path.
     */
    public function tier_2_user_does_not_see_redundant_mitid_login_cta(): void
    {
        $cprHash = hash('sha256', '1234567890');

        $response = $this->withSession(['signing_room_cpr' => $cprHash])
            ->get(route('signing-room.portal.signing-complete'))
            ->assertOk();

        $this->assertStringNotContainsString(
            route('signing-room.portal.auth.redirect'),
            $response->getContent(),
            'Tier 2 users already have a CPR session — offering them a MitID '
            .'login link is redundant and confusing.'
        );
    }

    // -------------------------------------------------------------------------
    // Branding-context preservation across the Idura redirect roundtrip
    // -------------------------------------------------------------------------

    /**
     * @test
     *
     * When SignDocument loads, it stores the envelope UUID in session so the
     * branding-resolver can run on /complete after the Idura roundtrip. Without
     * this anchor, /complete falls back to default Frankston branding even
     * for tenants with custom branding (Peter saw the Frankston logo instead
     * of Gesda's).
     */
    public function sign_document_mount_stores_envelope_uuid_in_session(): void
    {
        $envelope = $this->createEnvelope();
        $party    = $this->createParty($envelope);

        $this->get(route('signing-room.portal.sign', $party->uuid) . '?token=' . $party->signing_token)
            ->assertOk();

        $this->assertSame(
            $envelope->uuid,
            session('signing_room_active_envelope_uuid'),
            'SignDocument::mount must store envelope UUID in session so /complete '
            .'can resolve branding context after the Idura redirect.'
        );
    }

    /** @test */
    public function complete_page_resolves_envelope_branding_from_session(): void
    {
        $envelope = $this->createEnvelope(['title' => 'Aftale med Gesda Capital']);

        config(['signing-room.branding_resolver' => function ($userId) {
            return [
                'company_name' => 'Gesda Capital',
                'logo_url'     => 'https://cdn.example.com/gesda-logo.svg',
            ];
        }]);

        $response = $this->withSession(['signing_room_active_envelope_uuid' => $envelope->uuid])
            ->get(route('signing-room.portal.signing-complete'))
            ->assertOk();

        $this->assertStringContainsString(
            'gesda-logo.svg',
            $response->getContent(),
            'Branding-resolver must run on /complete when session carries the '
            .'envelope UUID, so tenant-branded logo replaces the Frankston default.'
        );
    }

    /**
     * @test
     *
     * Bilateral negative half — when the session has no envelope UUID (e.g.
     * a stale browser, or a Tier 2 user landing on /complete without ever
     * visiting /sign), the page must still render with default branding
     * rather than crash trying to look up a missing envelope.
     */
    public function complete_page_falls_back_to_default_branding_without_session_uuid(): void
    {
        config(['signing-room.branding_resolver' => function ($userId) {
            return [
                'company_name' => 'Should Not Appear',
                'logo_url'     => 'https://cdn.example.com/should-not-appear.svg',
            ];
        }]);

        $response = $this->get(route('signing-room.portal.signing-complete'))
            ->assertOk();

        $this->assertStringNotContainsString(
            'should-not-appear.svg',
            $response->getContent(),
            'With no session envelope UUID, /complete must not invoke the '
            .'branding-resolver — it has no envelope to feed it.'
        );
    }

    /**
     * @test
     *
     * Bilateral negative half — even with a session UUID set, if the envelope
     * was deleted or never existed, /complete must not crash; it must fall
     * back gracefully to default branding.
     */
    public function complete_page_falls_back_to_default_branding_when_envelope_missing(): void
    {
        $response = $this->withSession(['signing_room_active_envelope_uuid' => 'nonexistent-uuid-12345'])
            ->get(route('signing-room.portal.signing-complete'))
            ->assertOk();

        $this->assertStringContainsString(
            'Tak for din underskrift',
            $response->getContent(),
            'Page must render even when the session UUID points to a deleted '
            .'or never-existed envelope.'
        );
    }

    /**
     * @test
     *
     * Locks the data-integrity invariant flagged by the review: the resolver
     * must receive the envelope creator's user_id, not the visitor's identity
     * or some other stand-in. If a future refactor accidentally feeds the
     * resolver the wrong subject, tenant branding leaks across signers.
     */
    public function complete_page_invokes_branding_resolver_with_envelope_creator_id(): void
    {
        $envelope = $this->createEnvelope(['created_by' => 12345]);

        $captured = null;
        config(['signing-room.branding_resolver' => function ($userId) use (&$captured) {
            $captured = $userId;

            return ['company_name' => 'Test', 'logo_url' => null];
        }]);

        $this->withSession(['signing_room_active_envelope_uuid' => $envelope->uuid])
            ->get(route('signing-room.portal.signing-complete'))
            ->assertOk();

        $this->assertSame(
            12345,
            $captured,
            'Branding-resolver must receive envelope.created_by — not the visitor '
            .'user_id or any other stand-in. Mis-feeding this argument would leak '
            .'one tenant\'s branding into another tenant\'s signing flow.'
        );
    }

    /**
     * @test
     *
     * Multi-tab + shared-browser bleed prevention: after /complete renders
     * once, the session UUID must be consumed so a stale subsequent visit
     * does not inherit the prior signing flow's branding context.
     *
     * This locks the security/architecture-review fix: read-then-forget
     * (`session()->pull(...)`) instead of plain read.
     */
    public function complete_page_consumes_session_envelope_uuid_after_render(): void
    {
        $envelope = $this->createEnvelope();

        config(['signing-room.branding_resolver' => function ($userId) {
            return [
                'company_name' => 'First Tenant',
                'logo_url'     => 'https://cdn.example.com/first-tenant.svg',
            ];
        }]);

        // First visit: branding resolves from the seeded session UUID.
        $this->withSession(['signing_room_active_envelope_uuid' => $envelope->uuid])
            ->get(route('signing-room.portal.signing-complete'))
            ->assertOk()
            ->assertSee('first-tenant.svg');

        // Second visit, same session, no /sign in between: branding must NOT
        // bleed through. The session UUID was single-use and is now gone.
        $response = $this->get(route('signing-room.portal.signing-complete'))
            ->assertOk();

        $this->assertStringNotContainsString(
            'first-tenant.svg',
            $response->getContent(),
            'After /complete consumes the session envelope UUID, a follow-up '
            .'visit must render with default branding — otherwise a shared '
            .'browser leaks one tenant\'s identity into the next visitor\'s '
            .'experience (security F1+F2 from the multi-agent review).'
        );
    }
}
