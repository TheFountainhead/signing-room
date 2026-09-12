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
    public function the_mail_link_downloads_while_the_signing_iframe_stays_inline(): void
    {
        $envelope = $this->createEnvelope();
        $party = $this->createParty($envelope);

        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')
            ->put('signing-room/test-signed.pdf', '%PDF-1.4 signed');

        $base = route('signing-room.portal.pdf', $party->uuid)
            . '?token=' . $party->signing_token;

        // The completion mail's button must actually download. Laravel builds
        // the header itself, and Symfony's exact formatting varies (quotes are
        // omitted for token-safe names, and a filename*=utf-8'' fallback is
        // added for non-ASCII), so assert the parts that carry meaning rather
        // than the whole string.
        $response = $this->get($base . '&download=1')->assertOk();
        $disposition = $response->headers->get('Content-Disposition');

        $this->assertStringStartsWith('attachment', $disposition);
        $this->assertStringContainsString('allonge-til-kontrakt-signeret.pdf', $disposition);

        // The signing preview iframe must keep rendering in place.
        $this->get($base)
            ->assertOk()
            ->assertHeader('Content-Disposition', 'inline');
    }

    /**
     * Driven through the real public path (handleSigned -> completeEnvelope)
     * rather than by calling the private method: completion is a consequence
     * of the last signature, not something a caller triggers. Idura and the
     * disk are faked the same way SignedPdfUploadFailureTest does it.
     */
    #[Test]
    public function only_parties_who_signed_receive_the_completion_mail(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $envelope = $this->createEnvelope([
            'status'                   => EnvelopeStatus::Sent,
            'signed_document'          => null,
            'completed_at'             => null,
            'idura_signature_order_id' => 'SignatureOrder:tenant|order-abc',
        ]);

        // 🪤 isAllRoundsComplete() (SigningEnvelope:60) is NOT the same
        // predicate as unsignedCurrentRoundParties() (line 49): it requires
        // EVERY party with role=signer to have status=signed. A rejected
        // *signer* therefore blocks completion forever — by design, since a
        // rejected envelope is not a completed one. So both non-signing
        // parties here must be viewers, or completeEnvelope never runs.
        $rejected = $this->createParty($envelope, [
            'email'  => 'rejected@example.com',
            'status' => SigningPartyStatus::Rejected,
            'role'   => SigningPartyRole::Viewer->value,
        ]);

        // A viewer who never signed anything.
        $viewer = $this->createParty($envelope, [
            'email'  => 'viewer@example.com',
            'status' => SigningPartyStatus::Viewed,
            'role'   => SigningPartyRole::Viewer->value,
        ]);

        // The last outstanding signer — signing him completes the envelope.
        $signer = $this->createParty($envelope, [
            'email'  => 'signed@example.com',
            'status' => SigningPartyStatus::Pending,
        ]);

        $idura = $this->createMock(\Fountainhead\SigningRoom\Services\IduraSignatureService::class);
        $idura->method('closeOrder')->willReturn([
            'documents' => [['blob' => base64_encode('%PDF-1.4 signeret')]],
        ]);

        $disk = $this->createMock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->method('put')->willReturn(true);
        \Illuminate\Support\Facades\Storage::shouldReceive('disk')->andReturn($disk);

        (new \Fountainhead\SigningRoom\Services\SigningRoomService($idura))
            ->handleSigned($signer);

        $envelope->refresh();
        $this->assertEquals(EnvelopeStatus::Completed, $envelope->status);

        // EVERY party is told the envelope is final — this mail is their only
        // notification of it. Filtering recipients here would silently cut
        // viewers off, since a viewer is never notified to sign and so can
        // never reach status=signed (handleSigned:140 is the only writer).
        foreach ([$signer, $viewer, $rejected] as $party) {
            \Illuminate\Support\Facades\Notification::assertSentTo(
                $party,
                EnvelopeCompletedNotification::class,
            );
        }
    }

    /**
     * The key, not the mail, is what gets withheld: only a party who actually
     * signed receives a bearer token to the signed PDF.
     */
    #[Test]
    public function only_a_signer_gets_a_token_link_others_are_sent_to_the_portal(): void
    {
        $envelope = $this->createEnvelope();

        $signer = $this->createParty($envelope, [
            'email'  => 'signed@example.com',
            'status' => SigningPartyStatus::Signed,
        ]);
        $viewer = $this->createParty($envelope, [
            'email'  => 'viewer@example.com',
            'status' => SigningPartyStatus::Viewed,
            'role'   => SigningPartyRole::Viewer->value,
        ]);

        $signerHtml = $this->renderMailFor(new EnvelopeCompletedNotification($envelope), $signer);
        $viewerHtml = $this->renderMailFor(new EnvelopeCompletedNotification($envelope), $viewer);

        // The signer gets his own key, and a button that downloads.
        $this->assertStringContainsString($signer->signing_token, $signerHtml);
        $this->assertStringContainsString('download=1', $signerHtml);
        $this->assertStringContainsString('Download signeret dokument', $signerHtml);

        // The viewer is told, but handed no key at all — neither his own nor
        // anyone else's — and the label must not promise a download.
        $this->assertStringNotContainsString($viewer->signing_token, $viewerHtml);
        $this->assertStringNotContainsString($signer->signing_token, $viewerHtml);
        $this->assertStringNotContainsString('/pdf/', $viewerHtml);
        $this->assertStringContainsString(route('signing-room.portal.landing'), $viewerHtml);
        $this->assertStringNotContainsString('Download signeret dokument', $viewerHtml);
    }

    /**
     * Finding #8 from review: the rejected case was only ever exercised
     * against a rejected VIEWER, who is excluded by role anyway. A rejected
     * signer is the case that matters — he must not keep a key to a document
     * he refused to sign.
     */
    #[Test]
    public function a_signer_who_rejected_gets_no_token_link(): void
    {
        $envelope = $this->createEnvelope();

        $rejected = $this->createParty($envelope, [
            'email'  => 'rejected@example.com',
            'status' => SigningPartyStatus::Rejected,
            'role'   => SigningPartyRole::Signer->value,
        ]);

        $html = $this->renderMailFor(new EnvelopeCompletedNotification($envelope), $rejected);

        $this->assertStringNotContainsString($rejected->signing_token, $html);
        $this->assertStringNotContainsString('/pdf/', $html);
        $this->assertStringContainsString(route('signing-room.portal.landing'), $html);
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
    public function the_creator_is_linked_to_the_admin_page_not_the_party_download(): void
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

        // He is an admin, so he gets the admin page for the envelope.
        $this->assertStringContainsString(
            route('signing-room.admin.show', $envelope->uuid),
            $html,
        );

        // Not the envelope download route: he is not a party, and
        // routes/portal.php:50 requires the viewer to be one, so it 403s.
        $this->assertStringNotContainsString('/download/' . $envelope->uuid, $html);

        // And he must NOT be handed a party's bearer token. If the branch were
        // written the wrong way round, or dropped, this is what would catch it.
        foreach ($envelope->parties as $party) {
            $this->assertStringNotContainsString($party->signing_token, $html);
        }
        $this->assertStringNotContainsString('/pdf/', $html);
    }

    /**
     * Locks the fact that made the previous version of this test misleading:
     * it asserted the creator's link was PRESENT, never that it RESOLVED. The
     * creator is not a party, so the envelope download route refuses him —
     * measured, not assumed.
     */
    #[Test]
    public function the_download_route_refuses_a_logged_in_user_who_is_not_a_party(): void
    {
        $envelope = $this->createEnvelope();
        $this->createParty($envelope);

        $creator = new \Illuminate\Foundation\Auth\User();
        $creator->forceFill([
            'id'    => 1,
            'name'  => 'Frederik',
            'email' => 'fred@example.com',
        ]);

        $this->actingAs($creator)
            ->get(route('signing-room.portal.download', $envelope->uuid))
            ->assertForbidden();
    }
}
