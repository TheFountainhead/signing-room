<?php

namespace Fountainhead\SigningRoom\Tests\Feature;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Enums\SigningPartyRole;
use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
use Fountainhead\SigningRoom\Jobs\SyncIduraSignatureStatus;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Fountainhead\SigningRoom\Notifications\DocumentReadyNotification;
use Fountainhead\SigningRoom\Notifications\EnvelopeCompletedNotification;
use Fountainhead\SigningRoom\Notifications\PartySignedNotification;
use Fountainhead\SigningRoom\Services\SigningRoomService;
use Fountainhead\SigningRoom\Tests\Fixtures\FakeIdura;
use Fountainhead\SigningRoom\Tests\Fixtures\User;
use Fountainhead\SigningRoom\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * Kuvert 35 (prod, 25/11-2026) havde en kopimodtager (role=viewer) og kunne
 * aldrig fuldføres. sendEnvelope oprettede også kopimodtageren hos Idura, og
 * fordi rollen gik i det forældede fritekstfelt `role` i stedet for
 * `signatoryRole`, blev hun oprettet som SIGNER. Da sidste rigtige
 * underskriver underskrev, afviste Idura closeSignatureOrder med "Signature
 * order has open signatory". Exceptionen stoppede alt: ingen PDF, ingen
 * Completed, ingen mails, og SyncIduraSignatureStatus samlede den aldrig op.
 *
 * FakeIdura håndhæver samme regel som Idura: en åben SIGNER blokerer lukning.
 */
class ViewerCompletionTest extends TestCase
{
    use RefreshDatabase;

    private FakeIdura $idura;

    private SigningRoomService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(\App\Models\User::class)) {
            class_alias(User::class, \App\Models\User::class);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });

        Storage::fake('local');
        Storage::disk('local')->put('signing-room/original.pdf', '%PDF-1.4 original');

        Notification::fake();

        $this->idura = new FakeIdura;
        $this->app->instance(\Fountainhead\SigningRoom\Services\IduraSignatureService::class, $this->idura);
        $this->service = new SigningRoomService($this->idura);
    }

    private function creator(): User
    {
        return User::create(['name' => 'Afsender', 'email' => 'afsender@example.com']);
    }

    /**
     * @param  list<array{name: string, email: string, role?: string, signing_round?: int}>  $parties
     */
    private function sentEnvelope(array $parties, ?int $createdBy = null): SigningEnvelope
    {
        $envelope = $this->service->createEnvelope(
            title: 'Gældsbrev',
            pdfPath: 'signing-room/original.pdf',
            parties: $parties,
            createdBy: $createdBy,
        );

        return $this->service->sendEnvelope($envelope);
    }

    /** Simulate the SIGNATORY_SIGNED webhook: Idura marks it, we handle it. */
    private function sign(SigningParty $party): void
    {
        $this->idura->markSigned($party->idura_signatory_id);
        $this->service->handleSigned($party->fresh());
    }

    private function party(SigningEnvelope $envelope, string $email): SigningParty
    {
        return $envelope->parties()->where('email', $email)->firstOrFail();
    }

    #[Test]
    public function viewers_are_not_created_at_idura_and_signers_are_sent_with_signatory_role(): void
    {
        $envelope = $this->sentEnvelope([
            ['name' => 'Underskriver', 'email' => 'signer@example.com'],
            ['name' => 'Kopi', 'email' => 'viewer@example.com', 'role' => SigningPartyRole::Viewer->value],
        ]);

        $this->assertCount(1, $this->idura->addedInputs, 'Kun underskriveren må oprettes hos Idura.');
        $this->assertSame($this->party($envelope, 'signer@example.com')->uuid, $this->idura->addedInputs[0]['reference']);
        $this->assertSame('SIGNER', $this->idura->addedInputs[0]['signatory_role']);
        $this->assertArrayNotHasKey('role', $this->idura->addedInputs[0], 'Det forældede fritekstfelt role må ikke sendes.');

        $this->assertNull($this->party($envelope, 'viewer@example.com')->idura_signatory_id);
    }

    #[Test]
    public function an_envelope_with_a_viewer_completes_when_the_last_signer_signs(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([
            ['name' => 'Underskriver', 'email' => 'signer@example.com'],
            ['name' => 'Kopi', 'email' => 'viewer@example.com', 'role' => SigningPartyRole::Viewer->value],
        ]);

        $this->sign($this->party($envelope, 'signer@example.com'));

        Exceptions::assertNothingReported();

        $envelope->refresh();
        $this->assertEquals(EnvelopeStatus::Completed, $envelope->status);
        $this->assertSame('signing-room/' . $envelope->uuid . '/signed.pdf', $envelope->signed_document);
        Storage::disk('local')->assertExists($envelope->signed_document);

        // Kopimodtageren får sin kopi via completion-mailen, uden Idura-id.
        $viewer = $this->party($envelope, 'viewer@example.com');
        Notification::assertSentTo($viewer, EnvelopeCompletedNotification::class);
        Notification::assertNotSentTo($viewer, DocumentReadyNotification::class);

        $html = (string) (new EnvelopeCompletedNotification($envelope))->toMail($viewer)->render();
        $this->assertStringContainsString(route('signing-room.portal.landing'), $html);
    }

    #[Test]
    public function a_viewer_in_an_earlier_round_does_not_block_the_next_round(): void
    {
        $envelope = $this->sentEnvelope([
            ['name' => 'Runde 1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'Kopi', 'email' => 'viewer@example.com', 'role' => SigningPartyRole::Viewer->value, 'signing_round' => 1],
            ['name' => 'Runde 2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ]);

        $this->sign($this->party($envelope, 'r1@example.com'));

        $envelope->refresh();
        $this->assertSame(2, $envelope->current_round, 'Kopimodtageren må ikke holde runde 1 åben.');
        Notification::assertSentTo($this->party($envelope, 'r2@example.com'), DocumentReadyNotification::class);

        $this->sign($this->party($envelope, 'r2@example.com'));
        $this->assertEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
    }

    #[Test]
    public function a_failed_completion_is_reported_and_the_sync_job_completes_it_later(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([
            ['name' => 'Underskriver', 'email' => 'signer@example.com'],
        ]);

        $this->idura->failNextCloses = 1;
        $this->sign($this->party($envelope, 'signer@example.com'));

        Exceptions::assertReported(\RuntimeException::class);
        $this->assertNotEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
        Notification::assertNotSentTo($this->party($envelope, 'signer@example.com'), EnvelopeCompletedNotification::class);

        // Partiet er allerede signed, så det gamle job ville aldrig kalde
        // handleSigned igen. Det nye job fuldfører hængende kuverter.
        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);

        $envelope->refresh();
        $this->assertEquals(EnvelopeStatus::Completed, $envelope->status);
        $this->assertNotNull($envelope->signed_document);
        Notification::assertSentToTimes($this->party($envelope, 'signer@example.com'), EnvelopeCompletedNotification::class, 1);
    }

    #[Test]
    public function completion_runs_once_no_matter_how_often_it_is_retried(): void
    {
        $creator = $this->creator();

        $envelope = $this->sentEnvelope([
            ['name' => 'Underskriver', 'email' => 'signer@example.com'],
            ['name' => 'Kopi', 'email' => 'viewer@example.com', 'role' => SigningPartyRole::Viewer->value],
        ], $creator->id);

        $this->sign($this->party($envelope, 'signer@example.com'));

        // Webhook gentaget, sync-job kørt to gange, direkte genkald.
        $this->service->handleSigned($this->party($envelope, 'signer@example.com'));
        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);
        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);
        $this->assertFalse($this->service->completeEnvelope($envelope->fresh()));

        $this->assertSame(1, $this->idura->closeCalls, 'Ordren må kun lukkes én gang.');
        $this->assertSame(1, $envelope->events()->where('event_type', 'envelope.completed')->count());

        foreach (['signer@example.com', 'viewer@example.com'] as $email) {
            Notification::assertSentToTimes($this->party($envelope, $email), EnvelopeCompletedNotification::class, 1);
        }
        Notification::assertSentToTimes($creator, EnvelopeCompletedNotification::class, 1);
    }

    #[Test]
    public function the_sync_job_does_not_complete_while_a_signer_is_outstanding(): void
    {
        $envelope = $this->sentEnvelope([
            ['name' => 'A', 'email' => 'a@example.com'],
            ['name' => 'B', 'email' => 'b@example.com'],
        ]);

        $this->sign($this->party($envelope, 'a@example.com'));
        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);

        $this->assertSame(0, $this->idura->closeCalls);
        $this->assertEquals(EnvelopeStatus::PartiallySigned, $envelope->fresh()->status);
    }

    #[Test]
    public function the_creator_is_told_when_a_signer_signs_before_the_envelope_is_complete(): void
    {
        config(['signing-room.notifications.party_signed' => true]);

        $creator = $this->creator();

        $envelope = $this->sentEnvelope([
            ['name' => 'Anna Underskriver', 'email' => 'a@example.com'],
            ['name' => 'Bo Underskriver', 'email' => 'b@example.com'],
        ], $creator->id);

        $this->sign($this->party($envelope, 'a@example.com'));

        Notification::assertSentToTimes($creator, PartySignedNotification::class, 1);

        $sent = null;
        Notification::assertSentTo($creator, PartySignedNotification::class, function ($n) use (&$sent) {
            $sent = $n;

            return $n->signedParty->email === 'a@example.com';
        });

        // Tallene er låst ved underskriften, ikke ved rendering.
        $this->assertSame(1, $sent->signedCount);
        $this->assertSame(2, $sent->signerCount);

        $html = (string) $sent->toMail($creator)->render();
        $this->assertStringContainsString('Anna Underskriver har underskrevet dokumentet.', $html);
        $this->assertStringContainsString('1 af 2 underskrivere har underskrevet', $html);
        $this->assertStringContainsString(route('signing-room.admin.show', $envelope), $html);
        $this->assertStringNotContainsString('—', $html);
        $this->assertStringNotContainsString('–', $html);

        // Den sidste underskrift giver completion-mailen, ikke også en PartySigned.
        $this->sign($this->party($envelope, 'b@example.com'));

        Notification::assertSentToTimes($creator, PartySignedNotification::class, 1);
        Notification::assertSentToTimes($creator, EnvelopeCompletedNotification::class, 1);

        // Også efter at B har underskrevet, viser A's mail "1 af 2".
        $this->assertStringContainsString('1 af 2 underskrivere har underskrevet', (string) $sent->toMail($creator)->render());
    }

    #[Test]
    public function a_failing_creator_mail_does_not_stop_the_round_from_advancing(): void
    {
        config(['signing-room.notifications.party_signed' => true]);

        Exceptions::fake();

        $creator = $this->creator();

        $envelope = $this->sentEnvelope([
            ['name' => 'Runde 1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'Runde 2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ], $creator->id);

        $fake = Notification::getFacadeRoot();
        Notification::swap(new class($fake) extends \Illuminate\Support\Testing\Fakes\NotificationFake {
            public function __construct(private $inner) {}

            public function send($notifiables, $notification)
            {
                if ($notification instanceof PartySignedNotification) {
                    throw new \RuntimeException('Mailserver nede');
                }

                $this->inner->send($notifiables, $notification);
            }
        });

        $this->sign($this->party($envelope, 'r1@example.com'));

        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'Mailserver nede');
        $this->assertSame(2, $envelope->fresh()->current_round);
        $fake->assertSentTo($this->party($envelope, 'r2@example.com'), DocumentReadyNotification::class);
    }

    #[Test]
    public function an_envelope_without_signers_is_refused_and_never_counts_as_signed(): void
    {
        try {
            $this->service->createEnvelope(
                title: 'Kun kopi',
                pdfPath: 'signing-room/original.pdf',
                parties: [['name' => 'Kopi', 'email' => 'viewer@example.com', 'role' => SigningPartyRole::Viewer->value]],
            );
            $this->fail('createEnvelope skal afvise en kuvert uden underskrivere.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('En kuvert skal have mindst én underskriver.', $e->getMessage());
        }

        // En kuvert der alligevel findes (fx oprettet før denne regel).
        $envelope = SigningEnvelope::create([
            'title' => 'Kun kopi', 'status' => EnvelopeStatus::Sent,
            'original_document' => 'signing-room/original.pdf',
            'idura_signature_order_id' => 'SignatureOrder:fake',
            'total_rounds' => 1, 'current_round' => 1,
        ]);
        $envelope->parties()->create([
            'name' => 'Kopi', 'email' => 'viewer@example.com',
            'role' => SigningPartyRole::Viewer->value, 'status' => SigningPartyStatus::Pending, 'signing_round' => 1,
        ]);

        $this->assertFalse($envelope->isAllRoundsComplete());

        try {
            $this->service->sendEnvelope($envelope);
            $this->fail('sendEnvelope skal afvise en kuvert uden underskrivere.');
        } catch (\InvalidArgumentException) {
        }

        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);

        $this->assertSame(0, $this->idura->closeCalls);
        $this->assertNotEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
    }

    #[Test]
    public function a_viewer_created_at_idura_before_the_fix_still_blocks_its_round(): void
    {
        // Kuverter sendt før 25/11-2026: kopimodtageren findes hos Idura som
        // SIGNER, og Iduras signingSequence venter på hende.
        $envelope = $this->sentEnvelope([
            ['name' => 'Runde 1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'Kopi', 'email' => 'viewer@example.com', 'role' => SigningPartyRole::Viewer->value, 'signing_round' => 1],
            ['name' => 'Runde 2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ]);
        $this->party($envelope, 'viewer@example.com')->update(['idura_signatory_id' => 'Signatory:legacy-viewer']);

        $this->sign($this->party($envelope, 'r1@example.com'));

        $this->assertSame(1, $envelope->fresh()->current_round);
        Notification::assertNotSentTo($this->party($envelope, 'r2@example.com'), DocumentReadyNotification::class);
    }

    #[Test]
    public function the_same_signature_handled_twice_concurrently_is_processed_once(): void
    {
        config(['signing-room.notifications.party_signed' => true]);

        $creator = $this->creator();

        $envelope = $this->sentEnvelope([
            ['name' => 'Runde 1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'Runde 2', 'email' => 'r2@example.com', 'signing_round' => 2],
            ['name' => 'Runde 3', 'email' => 'r3@example.com', 'signing_round' => 3],
        ], $creator->id);

        // To processer (webhook og sync) har hver indlæst parten før nogen af
        // dem skrev: begge ser status != signed.
        $first = $this->party($envelope, 'r1@example.com');
        $second = $this->party($envelope, 'r1@example.com');
        $this->idura->markSigned($first->idura_signatory_id);

        $this->service->handleSigned($first);
        $this->service->handleSigned($second);

        $this->assertSame(2, $envelope->fresh()->current_round, 'Runden må kun rykkes én gang.');
        Notification::assertSentToTimes($this->party($envelope, 'r2@example.com'), DocumentReadyNotification::class, 1);
        Notification::assertSentToTimes($creator, PartySignedNotification::class, 1);
        $this->assertSame(1, $envelope->events()->where('event_type', 'party.signed')->count());
    }

    #[Test]
    public function two_signers_closing_the_same_round_concurrently_advance_it_once(): void
    {
        $envelope = $this->sentEnvelope([
            ['name' => 'Runde 1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'Runde 2', 'email' => 'r2@example.com', 'signing_round' => 2],
            ['name' => 'Runde 3', 'email' => 'r3@example.com', 'signing_round' => 3],
        ]);

        // Runde 1 er underskrevet; begge processer har kuverten indlæst i runde 1.
        $this->party($envelope, 'r1@example.com')->update(['status' => SigningPartyStatus::Signed]);
        $staleA = $envelope->fresh();
        $staleB = $envelope->fresh();

        $this->service->advanceRound($staleA);
        $this->service->advanceRound($staleB);

        $this->assertSame(2, $envelope->fresh()->current_round);
        Notification::assertSentToTimes($this->party($envelope, 'r2@example.com'), DocumentReadyNotification::class, 1);
        $this->assertSame(1, $envelope->events()->where('event_type', 'round.advanced')->count());
    }

    #[Test]
    public function a_retry_after_a_successful_close_fetches_the_retained_pdf_instead_of_closing_again(): void
    {
        $envelope = $this->sentEnvelope([['name' => 'Underskriver', 'email' => 'signer@example.com']]);
        $signer = $this->party($envelope, 'signer@example.com');
        $this->idura->markSigned($signer->idura_signatory_id);
        $signer->update(['status' => SigningPartyStatus::Signed, 'signed_at' => now()]);

        // Idura lukkede ordren i et tidligere forsøg; vores lagring fejlede.
        $this->idura->orderStatus = 'CLOSED';

        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);

        $envelope->refresh();
        $this->assertSame(0, $this->idura->closeCalls, 'closeSignatureOrder må ikke kaldes på en lukket ordre.');
        $this->assertSame(1, $this->idura->documentFetches);
        $this->assertEquals(EnvelopeStatus::Completed, $envelope->status);
        $this->assertSame('%PDF-1.4 signeret', Storage::disk('local')->get($envelope->signed_document));
    }

    #[Test]
    public function a_closed_order_whose_pdf_is_gone_is_reported_as_critical(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([['name' => 'Underskriver', 'email' => 'signer@example.com']]);
        $signer = $this->party($envelope, 'signer@example.com');
        $signer->update(['status' => SigningPartyStatus::Signed, 'signed_at' => now()]);

        $this->idura->orderStatus = 'CLOSED';
        $this->idura->retainedBlob = null;

        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);

        Exceptions::assertReported(fn (\Fountainhead\SigningRoom\Exceptions\SignedDocumentUnavailableException $e) => str_starts_with($e->getMessage(), 'KRITISK:'));
        $this->assertSame(0, $this->idura->closeCalls);
        $this->assertNotEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
    }

    #[Test]
    public function the_package_does_not_mail_the_creator_per_signature_unless_enabled(): void
    {
        // Standard: fra. Frankston-master og dannebrog-v1 sender selv en mail
        // via App\Listeners\NotifyAdminOnSignature på PartySignedEvent.
        $this->assertFalse(config('signing-room.notifications.party_signed'));

        $creator = $this->creator();
        $envelope = $this->sentEnvelope([
            ['name' => 'A', 'email' => 'a@example.com'],
            ['name' => 'B', 'email' => 'b@example.com'],
        ], $creator->id);

        \Illuminate\Support\Facades\Event::fake([\Fountainhead\SigningRoom\Events\PartySignedEvent::class]);

        $this->sign($this->party($envelope, 'a@example.com'));

        Notification::assertNotSentTo($creator, PartySignedNotification::class);
        \Illuminate\Support\Facades\Event::assertDispatchedTimes(\Fountainhead\SigningRoom\Events\PartySignedEvent::class, 1);
    }

    #[Test]
    public function a_legacy_viewer_blocks_completion_and_is_reported_once_as_an_action(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([
            ['name' => 'Underskriver', 'email' => 'signer@example.com'],
            ['name' => 'Kopi', 'email' => 'viewer@example.com', 'role' => SigningPartyRole::Viewer->value],
        ]);

        // Som før rettelsen: kopimodtageren er oprettet hos Idura som SIGNER.
        $this->idura->addSignatories('SignatureOrder:fake', [[
            'reference' => $this->party($envelope, 'viewer@example.com')->uuid,
            'signatory_role' => 'SIGNER',
        ]]);
        $legacyId = array_key_last($this->idura->signatories);
        $viewer = $this->party($envelope, 'viewer@example.com');
        $viewer->update(['idura_signatory_id' => $legacyId]);

        $this->sign($this->party($envelope, 'signer@example.com'));

        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);
        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);
        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);

        // Ingen gentagne lukkeforsøg hver time, og én tydelig handling.
        $this->assertSame(0, $this->idura->closeCalls);
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (\Fountainhead\SigningRoom\Exceptions\EnvelopeBlockedByViewerException $e) => str_contains($e->getMessage(), "Kuvert {$envelope->id} ")
            && str_contains($e->getMessage(), "slet viewer-signatory {$legacyId} hos Idura"));
        $this->assertSame(1, $envelope->events()->where('event_type', 'envelope.blocked')->count());
        $this->assertNotEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);

        // Genopretning: signatory slettet hos Idura og Idura-id ryddet på parten.
        unset($this->idura->signatories[$legacyId]);
        $viewer->update(['idura_signatory_id' => null]);

        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);

        $this->assertEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
        Notification::assertSentToTimes($viewer, EnvelopeCompletedNotification::class, 1);
    }

    #[Test]
    public function rounds_with_only_viewers_are_skipped_so_the_next_signer_is_mailed(): void
    {
        // Frankston-masters sekventielle tilstand: hver part sin egen runde.
        $envelope = $this->sentEnvelope([
            ['name' => 'Kopi 1', 'email' => 'v1@example.com', 'role' => SigningPartyRole::Viewer->value, 'signing_round' => 1],
            ['name' => 'Signer 2', 'email' => 's2@example.com', 'signing_round' => 2],
            ['name' => 'Kopi 3', 'email' => 'v3@example.com', 'role' => SigningPartyRole::Viewer->value, 'signing_round' => 3],
            ['name' => 'Signer 4', 'email' => 's4@example.com', 'signing_round' => 4],
        ]);

        $this->assertSame(2, $envelope->fresh()->current_round, 'Runde 1 har kun en kopimodtager og springes over ved afsendelse.');
        Notification::assertSentTo($this->party($envelope, 's2@example.com'), DocumentReadyNotification::class);
        Notification::assertNotSentTo($this->party($envelope, 's4@example.com'), DocumentReadyNotification::class);

        $this->sign($this->party($envelope, 's2@example.com'));

        $this->assertSame(4, $envelope->fresh()->current_round, 'Runde 3 har kun en kopimodtager og springes over.');
        Notification::assertSentToTimes($this->party($envelope, 's4@example.com'), DocumentReadyNotification::class, 1);

        $this->sign($this->party($envelope, 's4@example.com'));

        $this->assertEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
    }

    #[Test]
    public function the_sync_job_advances_a_round_left_stuck_after_a_signature(): void
    {
        $envelope = $this->sentEnvelope([
            ['name' => 'Runde 1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'Runde 2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ]);

        // Underskriften blev claimet, men handleSigned døde bagefter (fx 503
        // fra getSignatoryEvidence), før runden blev rykket.
        $r1 = $this->party($envelope, 'r1@example.com');
        $this->idura->markSigned($r1->idura_signatory_id);
        $r1->update(['status' => SigningPartyStatus::Signed, 'signed_at' => now()]);

        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);
        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);

        $this->assertSame(2, $envelope->fresh()->current_round);
        Notification::assertSentToTimes($this->party($envelope, 'r2@example.com'), DocumentReadyNotification::class, 1);
        $this->assertSame(1, $envelope->events()->where('event_type', 'round.advanced')->count());
    }
}
