<?php

namespace Fountainhead\SigningRoom\Tests\Feature;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Fountainhead\SigningRoom\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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
        // uuid and signing_token are auto-generated in booted()
    }

    // -------------------------------------------------------------------------
    // Token validation on /sign/{uuid}
    // -------------------------------------------------------------------------

    /** @test */
    public function signing_link_without_token_returns_403(): void
    {
        $envelope = $this->createEnvelope();
        $party    = $this->createParty($envelope);

        $this->get(route('signing-room.portal.sign', $party->uuid))
            ->assertForbidden();
    }

    /** @test */
    public function signing_link_with_wrong_token_returns_403(): void
    {
        $envelope = $this->createEnvelope();
        $party    = $this->createParty($envelope);

        $this->get(route('signing-room.portal.sign', $party->uuid) . '?token=wrong-token')
            ->assertForbidden();
    }

    /** @test */
    public function signing_link_with_valid_token_returns_200(): void
    {
        $envelope = $this->createEnvelope();
        $party    = $this->createParty($envelope);

        $this->get(route('signing-room.portal.sign', $party->uuid) . '?token=' . $party->signing_token)
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // Dashboard requires CPR session
    // -------------------------------------------------------------------------

    /** @test */
    public function dashboard_without_cpr_session_redirects_to_landing(): void
    {
        $response = $this->get(route('signing-room.portal.dashboard'));

        // Livewire's $this->redirect() issues a 302 back to the landing route
        $response->assertRedirect(route('signing-room.portal.landing'));
    }

    // -------------------------------------------------------------------------
    // Landing page content
    // -------------------------------------------------------------------------

    /** @test */
    public function landing_page_shows_mitid_button(): void
    {
        // hasCriiptoVerify = true because criipto_verify.client_id is set in TestCase
        $this->get(route('signing-room.portal.landing'))
            ->assertOk()
            ->assertSee('Log ind med MitID');
    }

    /** @test */
    public function landing_page_does_not_show_email_input_by_default(): void
    {
        // The email input only appears when verify_email session flag is set
        $this->get(route('signing-room.portal.landing'))
            ->assertOk()
            ->assertDontSee('<input type="email"', escape: false);
    }

    // -------------------------------------------------------------------------
    // MitID callback: orphan backfill
    // -------------------------------------------------------------------------

    /**
     * @test
     *
     * Regression: after a successful MitID login, any other parties that share
     * the user's email but have no cpr_hash must be linked to this CPR too.
     *
     * Scenario: user signed a new envelope, but handleSigned() could not fetch
     * CPR evidence from Idura, so cpr_hash stayed NULL on that party. On next
     * MitID login, login succeeded via an older matching party, but the new
     * orphan party stayed invisible on the dashboard. Fix: backfill orphans by
     * email after login.
     */
    public function mitid_login_backfills_orphan_parties_with_matching_email(): void
    {
        $cpr     = '1234567890';
        $cprHash = hash('sha256', $cpr);
        $email   = 'fred@frankston.io';

        // An older envelope the user already signed — their cpr_hash is populated
        $existingEnvelope = $this->createEnvelope(['title' => 'Old Agreement']);
        $this->createParty($existingEnvelope, [
            'email'    => $email,
            'cpr_hash' => $cprHash,
            'status'   => SigningPartyStatus::Signed,
        ]);

        // New envelope: user signed, but Idura evidence fetch failed, so cpr_hash is NULL
        $orphanEnvelope = $this->createEnvelope(['title' => 'New Agreement']);
        $orphan = $this->createParty($orphanEnvelope, [
            'email'    => $email,
            'cpr_hash' => null,
            'status'   => SigningPartyStatus::Signed,
        ]);

        // Build a JWT with the CPR — signature is not validated in callback, only iss/aud
        $domain   = config('signing-room.criipto_verify.domain');
        $clientId = config('signing-room.criipto_verify.client_id');
        $payload  = strtr(base64_encode(json_encode([
            'iss'                 => 'https://' . $domain,
            'aud'                 => $clientId,
            'cprNumberIdentifier' => $cpr,
        ])), '+/', '-_');
        $idToken  = 'header.' . $payload . '.signature';

        Http::fake([
            'https://' . $domain . '/oauth2/token' => Http::response(['id_token' => $idToken]),
        ]);

        $state = 'test-state';

        $response = $this->withSession(['signing_room_oauth_state' => $state])
            ->get(route('signing-room.portal.auth.callback', [
                'code'  => 'test-code',
                'state' => $state,
            ]));

        $response->assertRedirect(route('signing-room.portal.dashboard'));

        // The orphan party must now have cpr_hash populated
        $orphan->refresh();
        $this->assertSame($cprHash, $orphan->cpr_hash);
    }
}
