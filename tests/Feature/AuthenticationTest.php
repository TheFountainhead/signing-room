<?php

namespace Fountainhead\SigningRoom\Tests\Feature;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Fountainhead\SigningRoom\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
}
