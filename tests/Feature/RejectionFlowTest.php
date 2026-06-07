<?php

namespace Fountainhead\SigningRoom\Tests\Feature;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
use Fountainhead\SigningRoom\Livewire\Portal\SignDocument;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Fountainhead\SigningRoom\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Kristian reported (jun 2026): "da jeg afviste kom jeg ikke tilbage" — the
 * sibling bug to FHT-1962, but for the rejection path. Rejecting in-app
 * re-rendered the same page with no way onward, and rejecting at the Idura/
 * MitID page redirected to /complete which unconditionally claimed
 * "Tak for din underskrift".
 *
 * Fix: in-app reject redirects to /complete, and /complete resolves the
 * party's actual outcome from our own DB (via the party uuid anchored in the
 * session by SignDocument::mount) — never from URL parameters.
 */
class RejectionFlowTest extends TestCase
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

    /** @test */
    public function rejecting_in_app_redirects_to_the_outcome_page(): void
    {
        $envelope = $this->createEnvelope();
        $party = $this->createParty($envelope);

        Livewire::test(SignDocument::class, ['signingParty' => $party])
            ->set('rejectionReason', 'Beløbet er forkert')
            ->call('reject')
            ->assertRedirect(route('signing-room.portal.signing-complete'));

        $party->refresh();
        $this->assertEquals(SigningPartyStatus::Rejected, $party->status);
        $this->assertEquals('Beløbet er forkert', $party->rejection_reason);
    }

    /** @test */
    public function sign_page_anchors_party_uuid_in_session(): void
    {
        $envelope = $this->createEnvelope();
        $party = $this->createParty($envelope);

        $this->get(route('signing-room.portal.sign', $party->uuid) . '?token=' . $party->signing_token)
            ->assertOk()
            ->assertSessionHas('signing_room_active_party_uuid', $party->uuid);
    }

    /** @test */
    public function complete_page_shows_rejection_outcome_for_rejected_party(): void
    {
        $envelope = $this->createEnvelope();
        $party = $this->createParty($envelope, [
            'status'      => SigningPartyStatus::Rejected,
            'rejected_at' => now(),
        ]);

        $this->withSession(['signing_room_active_party_uuid' => $party->uuid])
            ->get(route('signing-room.portal.signing-complete'))
            ->assertOk()
            ->assertSee('Dokument afvist')
            ->assertDontSee('Tak for din underskrift');
    }

    /** @test */
    public function complete_page_still_offers_a_path_onward_after_rejection(): void
    {
        $envelope = $this->createEnvelope();
        $party = $this->createParty($envelope, [
            'status'      => SigningPartyStatus::Rejected,
            'rejected_at' => now(),
        ]);

        // The whole point of the fix: rejecting must not dead-end. Tier 1
        // (token-link) users get the MitID login CTA, mirroring FHT-1962.
        $this->withSession(['signing_room_active_party_uuid' => $party->uuid])
            ->get(route('signing-room.portal.signing-complete'))
            ->assertSee('Log ind med MitID');
    }

    /** @test */
    public function complete_page_defaults_to_signed_copy_without_party_session(): void
    {
        $this->get(route('signing-room.portal.signing-complete'))
            ->assertOk()
            ->assertSee('Tak for din underskrift')
            ->assertDontSee('Dokument afvist');
    }

    /** @test */
    public function complete_page_shows_signed_copy_for_signed_party(): void
    {
        $envelope = $this->createEnvelope();
        $party = $this->createParty($envelope, [
            'status'    => SigningPartyStatus::Signed,
            'signed_at' => now(),
        ]);

        $this->withSession(['signing_room_active_party_uuid' => $party->uuid])
            ->get(route('signing-room.portal.signing-complete'))
            ->assertSee('Tak for din underskrift')
            ->assertDontSee('Dokument afvist');
    }

    /** @test */
    public function party_session_key_is_single_use(): void
    {
        $envelope = $this->createEnvelope();
        $party = $this->createParty($envelope, [
            'status'      => SigningPartyStatus::Rejected,
            'rejected_at' => now(),
        ]);

        $this->withSession(['signing_room_active_party_uuid' => $party->uuid])
            ->get(route('signing-room.portal.signing-complete'))
            ->assertSessionMissing('signing_room_active_party_uuid');
    }
}
