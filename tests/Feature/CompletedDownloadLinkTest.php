<?php

namespace Fountainhead\SigningRoom\Tests\Feature;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Enums\SigningPartyRole;
use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Fountainhead\SigningRoom\Notifications\EnvelopeCompletedNotification;
use Fountainhead\SigningRoom\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ulrik Larsen (Draupnir) reported 11 sep 2026 that he could not retrieve the
 * Allonge he had just signed: the "Download signeret dokument" button in the
 * completion mail landed on
 * https://sign.frankston.io/download/42a90e6d-... => 403
 * "Du har ikke adgang til dette dokument."
 *
 * Root cause: EnvelopeCompletedNotification linked to the envelope-keyed route
 * `signing-room.portal.download`, which admits a visitor only via a live MitID
 * portal session (`signing_room_cpr`) or an authenticated admin user. An
 * external signer clicking from his mail client has neither, so every such
 * signer is refused once his signing session is gone.
 *
 * Fix: link parties to the party-keyed `signing-room.portal.pdf` route with
 * their `signing_token`, exactly as DocumentReadyNotification and
 * SigningReminderNotification already do via SigningParty::signingUrl().
 * `signing_token` is written once in SigningParty::booted() and never cleared,
 * so it remains valid after completion.
 *
 * The creator is notified with the SAME notification class
 * (SigningRoomService::notifyCreator) but is an \App\Models\User, which has
 * neither `uuid` nor `signing_token`. The notifiable type must therefore be
 * branched — the creator keeps the envelope-keyed URL, which works for him
 * through the route's authenticated-admin branch.
 */
class CompletedDownloadLinkTest extends TestCase
{
    use RefreshDatabase;

    private function createEnvelope(array $attributes = []): SigningEnvelope
    {
        return SigningEnvelope::create(array_merge([
            'title'             => 'Allonge til kontrakt',
            'status'            => EnvelopeStatus::Completed,
            'original_document' => 'signing-room/test.pdf',
            'signed_document'   => 'signing-room/test-signed.pdf',
            'completed_at'      => now(),
            'total_rounds'      => 1,
            'current_round'     => 1,
        ], $attributes));
    }

    private function createParty(SigningEnvelope $envelope, array $attributes = []): SigningParty
    {
        return SigningParty::create(array_merge([
            'signing_envelope_id' => $envelope->id,
            'name'                => 'Ulrik Larsen',
            'email'               => 'ul@example.com',
            'status'              => SigningPartyStatus::Signed,
            'role'                => SigningPartyRole::Signer->value,
            'signing_round'       => 1,
        ], $attributes));
    }

    /**
     * Render the mail the party actually receives and return its HTML.
     */
    private function renderMailFor(EnvelopeCompletedNotification $notification, $notifiable): string
    {
        return (string) $notification->toMail($notifiable)->render();
    }

    #[Test]
    public function completion_mail_links_a_party_to_a_token_url_not_the_session_gated_download(): void
    {
        $envelope = $this->createEnvelope();
        $party = $this->createParty($envelope);

        $html = $this->renderMailFor(new EnvelopeCompletedNotification($envelope), $party);

        // The link must carry the party's own token, so no session is needed.
        $this->assertStringContainsString($party->signing_token, $html);
        $this->assertStringContainsString($party->uuid, $html);

        // It must NOT be the envelope-keyed route that produced Ulrik's 403.
        $this->assertStringNotContainsString('/download/' . $envelope->uuid, $html);
    }

    #[Test]
    public function a_party_can_open_the_signed_document_from_the_mail_without_any_session(): void
    {
        $envelope = $this->createEnvelope();
        $party = $this->createParty($envelope);

        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')
            ->put('signing-room/test-signed.pdf', '%PDF-1.4 signed');

        // No session(), no actingAs() — exactly Ulrik's situation.
        $this->get(route('signing-room.portal.pdf', $party->uuid) . '?token=' . $party->signing_token)
            ->assertOk();
    }

    #[Test]
    public function the_document_is_still_refused_without_a_valid_token(): void
    {
        $envelope = $this->createEnvelope();
        $party = $this->createParty($envelope);

        // Missing token.
        $this->get(route('signing-room.portal.pdf', $party->uuid))
            ->assertForbidden();

        // Wrong token.
        $this->get(route('signing-room.portal.pdf', $party->uuid) . '?token=' . str_repeat('a', 64))
            ->assertForbidden();
    }

    #[Test]
    public function the_creator_still_receives_a_working_mail(): void
    {
        $envelope = $this->createEnvelope();
        $this->createParty($envelope);

        // SigningRoomService::notifyCreator resolves \App\Models\User, which
        // exists in the host app but not in the Testbench skeleton this package
        // is tested against. What toMail() actually branches on is
        // `instanceof SigningParty`, so any non-party notifiable exercises the
        // same path the creator takes in production.
        $creator = new \Illuminate\Foundation\Auth\User();
        $creator->forceFill([
            'id'    => 1,
            'name'  => 'Frederik',
            'email' => 'fred@example.com',
        ]);

        $html = $this->renderMailFor(new EnvelopeCompletedNotification($envelope), $creator);

        // He keeps the envelope-keyed link, which his admin session satisfies.
        $this->assertStringContainsString('/download/' . $envelope->uuid, $html);

        // And he must NOT be handed a party's bearer token. If the branch were
        // written the wrong way round, or dropped, this is what would catch it.
        foreach ($envelope->parties as $party) {
            $this->assertStringNotContainsString($party->signing_token, $html);
        }
        $this->assertStringNotContainsString('/pdf/', $html);
    }
}
