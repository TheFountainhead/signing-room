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
     * Fake the Criipto token endpoint so the callback can decode a JWT with
     * an arbitrary CPR claim. Returns the state value to replay in the request.
     */
    private function fakeCriiptoCallback(string $cpr): string
    {
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

        return 'test-state';
    }

    /** @test */
    public function mitid_login_backfills_orphan_parties_with_matching_email(): void
    {
        // Happy path: user has one confirmed party with cpr_hash populated on
        // their dedicated email, plus one orphan (Idura evidence fetch failed).
        // Login should safely backfill the orphan.
        $cpr     = '1234567890';
        $cprHash = hash('sha256', $cpr);
        $email   = 'fred@frankston.io';

        $existing = $this->createEnvelope(['title' => 'Old Agreement']);
        $this->createParty($existing, [
            'email'    => $email,
            'cpr_hash' => $cprHash,
            'status'   => SigningPartyStatus::Signed,
        ]);

        $orphanEnvelope = $this->createEnvelope(['title' => 'New Agreement']);
        $orphan = $this->createParty($orphanEnvelope, [
            'email'    => $email,
            'cpr_hash' => null,
            'status'   => SigningPartyStatus::Signed,
        ]);

        $state = $this->fakeCriiptoCallback($cpr);

        $response = $this->withSession(['signing_room_oauth_state' => $state])
            ->get(route('signing-room.portal.auth.callback', [
                'code'  => 'test-code',
                'state' => $state,
            ]));

        $response->assertRedirect(route('signing-room.portal.dashboard'));
        $this->assertSame($cprHash, $orphan->refresh()->cpr_hash);
    }

    /**
     * @test
     *
     * P1 REGRESSION: a shared email with two or more known CPRs must never
     * cause one user's login to overwrite another user's orphan party.
     */
    public function mitid_login_refuses_to_backfill_orphans_on_shared_email(): void
    {
        $mine       = '1234567890';
        $mineHash   = hash('sha256', $mine);
        $othersHash = hash('sha256', '9999999999');
        $shared     = 'info@advokatfirma.dk';

        // A confirmed party for ME
        $mineEnvelope = $this->createEnvelope(['title' => 'My Envelope']);
        $this->createParty($mineEnvelope, [
            'email'    => $shared,
            'cpr_hash' => $mineHash,
            'status'   => SigningPartyStatus::Signed,
        ]);

        // Someone else's confirmed party on the same shared mailbox
        $othersEnvelope = $this->createEnvelope(['title' => 'Colleague Envelope']);
        $this->createParty($othersEnvelope, [
            'email'    => $shared,
            'cpr_hash' => $othersHash,
            'status'   => SigningPartyStatus::Signed,
        ]);

        // A colleague's orphan on the same shared mailbox
        $orphanEnvelope = $this->createEnvelope(['title' => 'Colleague Orphan']);
        $orphan = $this->createParty($orphanEnvelope, [
            'email'    => $shared,
            'cpr_hash' => null,
            'status'   => SigningPartyStatus::Signed,
        ]);

        $state = $this->fakeCriiptoCallback($mine);

        $this->withSession(['signing_room_oauth_state' => $state])
            ->get(route('signing-room.portal.auth.callback', [
                'code'  => 'test-code',
                'state' => $state,
            ]))
            ->assertRedirect(route('signing-room.portal.dashboard'));

        // The orphan must NOT be linked — shared mailbox ambiguity makes this unsafe
        $this->assertNull($orphan->refresh()->cpr_hash);
    }

    /** @test */
    public function mitid_login_refuses_to_backfill_orphan_with_mismatched_cpr_last_four(): void
    {
        $cpr     = '1234567890'; // last 4 = 7890
        $cprHash = hash('sha256', $cpr);
        $email   = 'fred@frankston.io';

        $existing = $this->createEnvelope(['title' => 'Old Agreement']);
        $this->createParty($existing, [
            'email'    => $email,
            'cpr_hash' => $cprHash,
            'status'   => SigningPartyStatus::Signed,
        ]);

        // Orphan has an explicit cpr_last_four that does NOT match — likely a
        // different person who happens to share the mailbox but whose last four
        // was recorded by the envelope creator
        $orphanEnvelope = $this->createEnvelope(['title' => 'Suspicious Orphan']);
        $orphan = $this->createParty($orphanEnvelope, [
            'email'          => $email,
            'cpr_hash'       => null,
            'cpr_last_four'  => '0000',
            'status'         => SigningPartyStatus::Signed,
        ]);

        $state = $this->fakeCriiptoCallback($cpr);

        $this->withSession(['signing_room_oauth_state' => $state])
            ->get(route('signing-room.portal.auth.callback', [
                'code'  => 'test-code',
                'state' => $state,
            ]))
            ->assertRedirect(route('signing-room.portal.dashboard'));

        $this->assertNull($orphan->refresh()->cpr_hash);
    }

    /** @test */
    public function mitid_login_backfills_orphan_with_matching_cpr_last_four(): void
    {
        $cpr     = '1234567890'; // last 4 = 7890
        $cprHash = hash('sha256', $cpr);
        $email   = 'fred@frankston.io';

        $existing = $this->createEnvelope(['title' => 'Old Agreement']);
        $this->createParty($existing, [
            'email'    => $email,
            'cpr_hash' => $cprHash,
            'status'   => SigningPartyStatus::Signed,
        ]);

        $orphanEnvelope = $this->createEnvelope(['title' => 'Confirmed Orphan']);
        $orphan = $this->createParty($orphanEnvelope, [
            'email'         => $email,
            'cpr_hash'      => null,
            'cpr_last_four' => '7890',
            'status'        => SigningPartyStatus::Signed,
        ]);

        $state = $this->fakeCriiptoCallback($cpr);

        $this->withSession(['signing_room_oauth_state' => $state])
            ->get(route('signing-room.portal.auth.callback', [
                'code'  => 'test-code',
                'state' => $state,
            ]))
            ->assertRedirect(route('signing-room.portal.dashboard'));

        $this->assertSame($cprHash, $orphan->refresh()->cpr_hash);
    }

    /** @test */
    public function mitid_login_logs_audit_event_when_backfilling_orphan(): void
    {
        $cpr     = '1234567890';
        $cprHash = hash('sha256', $cpr);
        $email   = 'fred@frankston.io';

        $existing = $this->createEnvelope(['title' => 'Old Agreement']);
        $this->createParty($existing, [
            'email'    => $email,
            'cpr_hash' => $cprHash,
            'status'   => SigningPartyStatus::Signed,
        ]);

        $orphanEnvelope = $this->createEnvelope(['title' => 'New Agreement']);
        $orphan = $this->createParty($orphanEnvelope, [
            'email'    => $email,
            'cpr_hash' => null,
            'status'   => SigningPartyStatus::Signed,
        ]);

        $state = $this->fakeCriiptoCallback($cpr);

        $this->withSession(['signing_room_oauth_state' => $state])
            ->get(route('signing-room.portal.auth.callback', [
                'code'  => 'test-code',
                'state' => $state,
            ]));

        // An audit event must be recorded so we can forensically trace which
        // login linked which orphan party to which CPR hash
        $this->assertTrue(
            $orphanEnvelope->events()
                ->where('event_type', \Fountainhead\SigningRoom\Enums\SigningEventType::PartyCprLinked->value)
                ->where('signing_party_id', $orphan->id)
                ->exists(),
            'Expected a PartyCprLinked event after email-based backfill',
        );
    }
}
