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
        } catch (\Fountainhead\SigningRoom\Exceptions\EnvelopeHasNoSignerException $e) {
            $this->assertSame('En kuvert skal have mindst én underskriver.', $e->getMessage());
            $this->assertInstanceOf(\InvalidArgumentException::class, $e, 'Bagudkompatibel for hosts, der fanger InvalidArgumentException.');
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
        } catch (\Fountainhead\SigningRoom\Exceptions\EnvelopeHasNoSignerException) {
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

    #[Test]
    public function a_rejection_stops_the_round_from_advancing_and_the_sync_job_leaves_it(): void
    {
        $envelope = $this->sentEnvelope([
            ['name' => 'A', 'email' => 'a@example.com', 'signing_round' => 1],
            ['name' => 'B', 'email' => 'b@example.com', 'signing_round' => 1],
            ['name' => 'Runde 2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ]);

        $this->service->handleRejected($this->party($envelope, 'a@example.com'), 'Forkert beløb');
        $this->sign($this->party($envelope, 'b@example.com'));

        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);

        $this->assertSame(1, $envelope->fresh()->current_round, 'En afvist runde må ikke rykkes.');
        Notification::assertNotSentTo($this->party($envelope, 'r2@example.com'), DocumentReadyNotification::class);
        $this->assertSame(0, $this->idura->closeCalls);
    }

    #[Test]
    public function a_legacy_viewer_blocking_round_one_is_reported_even_with_signers_left_in_round_two(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([
            ['name' => 'Runde 1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'Kopi', 'email' => 'viewer@example.com', 'role' => SigningPartyRole::Viewer->value, 'signing_round' => 1],
            ['name' => 'Runde 2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ]);
        $this->party($envelope, 'viewer@example.com')->update(['idura_signatory_id' => 'Signatory:legacy-viewer']);

        $this->sign($this->party($envelope, 'r1@example.com'));

        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);
        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (\Fountainhead\SigningRoom\Exceptions\EnvelopeBlockedByViewerException $e) => str_contains($e->getMessage(), 'slet viewer-signatory Signatory:legacy-viewer hos Idura')
            && str_contains($e->getMessage(), 'Runde 1 venter kun på kopimodtageren'));
        $this->assertSame(1, $envelope->fresh()->current_round);
    }

    #[Test]
    public function a_late_partial_update_cannot_reopen_a_completed_envelope(): void
    {
        $envelope = $this->sentEnvelope([
            ['name' => 'A', 'email' => 'a@example.com'],
            ['name' => 'B', 'email' => 'b@example.com'],
            ['name' => 'C', 'email' => 'c@example.com'],
        ]);

        // En samtidig proces har netop fuldført kuverten (completed_at sat),
        // mens denne proces stadig tror, den er delvist underskrevet.
        SigningEnvelope::whereKey($envelope->id)->update(['completed_at' => now(), 'status' => EnvelopeStatus::Sent->value]);

        $this->sign($this->party($envelope, 'a@example.com'));

        $this->assertEquals(EnvelopeStatus::Sent, $envelope->fresh()->status, 'PartiallySigned må ikke skrives oven på en fuldført kuvert.');
    }

    #[Test]
    public function a_completed_envelope_whose_status_was_overwritten_is_not_completed_again(): void
    {
        $creator = $this->creator();
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']], $creator->id);

        $this->sign($this->party($envelope, 'a@example.com'));
        $this->assertEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);

        // Overskrevet af en forsinket webhook.
        SigningEnvelope::whereKey($envelope->id)->update(['status' => EnvelopeStatus::PartiallySigned->value]);

        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);

        $this->assertSame(1, $this->idura->closeCalls);
        $this->assertEquals(EnvelopeStatus::Completed, $envelope->fresh()->status, 'Status rettes tilbage.');
        Notification::assertSentToTimes($this->party($envelope, 'a@example.com'), EnvelopeCompletedNotification::class, 1);
        Notification::assertSentToTimes($creator, EnvelopeCompletedNotification::class, 1);
    }

    #[Test]
    public function an_evidence_failure_after_the_claim_does_not_lose_the_event_or_the_round(): void
    {
        Exceptions::fake();
        \Illuminate\Support\Facades\Event::fake([\Fountainhead\SigningRoom\Events\PartySignedEvent::class]);

        $envelope = $this->sentEnvelope([
            ['name' => 'Runde 1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'Runde 2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ]);

        $this->idura->failEvidence = true;
        $this->sign($this->party($envelope, 'r1@example.com'));

        Exceptions::assertReported(fn (\RuntimeException $e) => str_contains($e->getMessage(), 'evidence 503'));
        $this->assertSame(1, $envelope->events()->where('event_type', 'party.signed')->count());
        \Illuminate\Support\Facades\Event::assertDispatchedTimes(\Fountainhead\SigningRoom\Events\PartySignedEvent::class, 1);
        $this->assertSame(2, $envelope->fresh()->current_round);
    }

    #[Test]
    public function the_sync_job_backfills_a_signature_whose_follow_up_never_ran_once(): void
    {
        \Illuminate\Support\Facades\Event::fake([\Fountainhead\SigningRoom\Events\PartySignedEvent::class]);

        $envelope = $this->sentEnvelope([
            ['name' => 'Gammel', 'email' => 'old@example.com', 'signing_round' => 1],
            ['name' => 'Ny', 'email' => 'new@example.com', 'signing_round' => 1],
            ['name' => 'Runde 2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ]);

        // Claimet, men processen døde før party.signed og PartySignedEvent.
        foreach (['old@example.com' => 10, 'new@example.com' => 1] as $email => $minutesAgo) {
            $party = $this->party($envelope, $email);
            $this->idura->markSigned($party->idura_signatory_id);
            $party->update(['status' => SigningPartyStatus::Signed, 'signed_at' => now()->subMinutes($minutesAgo)]);
        }

        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);
        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);

        $old = $this->party($envelope, 'old@example.com');
        $new = $this->party($envelope, 'new@example.com');

        $this->assertSame(1, $envelope->events()->where('event_type', 'party.signed')->where('signing_party_id', $old->id)->count());
        $this->assertSame(0, $envelope->events()->where('event_type', 'party.signed')->where('signing_party_id', $new->id)->count(), 'En frisk underskrift kan stadig være i gang i en webhook.');
        \Illuminate\Support\Facades\Event::assertDispatchedTimes(\Fountainhead\SigningRoom\Events\PartySignedEvent::class, 1);
    }

    #[Test]
    public function a_cancelled_or_expired_idura_order_is_reported_once_and_never_closed(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $this->idura->orderStatus = 'CANCELLED';

        $this->sign($this->party($envelope, 'a@example.com'));
        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);
        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);

        $this->assertSame(0, $this->idura->closeCalls, 'closeSignatureOrder må ikke forsøges på en annulleret ordre.');
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (\Fountainhead\SigningRoom\Exceptions\EnvelopeOrderNotOpenException $e) => str_contains($e->getMessage(), 'er CANCELLED og kan ikke lukkes'));
        $this->assertNotEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
    }

    #[Test]
    public function sending_a_draft_without_signers_from_the_admin_page_shows_an_error(): void
    {
        $envelope = SigningEnvelope::create([
            'title' => 'Kun kopi', 'status' => EnvelopeStatus::Draft,
            'original_document' => 'signing-room/original.pdf',
            'total_rounds' => 1, 'current_round' => 1,
        ]);
        $envelope->parties()->create([
            'name' => 'Kopi', 'email' => 'viewer@example.com',
            'role' => SigningPartyRole::Viewer->value, 'status' => SigningPartyStatus::Pending, 'signing_round' => 1,
        ]);

        $component = new \Fountainhead\SigningRoom\Livewire\Admin\EnvelopeShow;
        $component->signingEnvelope = $envelope;
        $component->sendEnvelope();

        $this->assertSame('En kuvert skal have mindst én underskriver.', session('error'));
        $this->assertNull(session('success'));
        $this->assertEquals(EnvelopeStatus::Draft, $envelope->fresh()->status);
    }

    private function addLegacyViewer(SigningEnvelope $envelope, string $email): string
    {
        $viewer = $this->party($envelope, $email);
        $this->idura->addSignatories('SignatureOrder:fake', [[
            'reference' => $viewer->uuid,
            'signatory_role' => 'SIGNER',
            'signing_sequence' => $viewer->signing_round,
        ]]);
        $id = array_key_last($this->idura->signatories);
        $viewer->update(['idura_signatory_id' => $id]);

        return $id;
    }

    private function sync(): void
    {
        (new SyncIduraSignatureStatus)->handle($this->idura, $this->service);
    }

    #[Test]
    public function a_second_legacy_viewer_in_a_later_round_is_reported_too(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([
            ['name' => 'R1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'Kopi 1', 'email' => 'v1@example.com', 'role' => SigningPartyRole::Viewer->value, 'signing_round' => 1],
            ['name' => 'R2', 'email' => 'r2@example.com', 'signing_round' => 2],
            ['name' => 'Kopi 2', 'email' => 'v2@example.com', 'role' => SigningPartyRole::Viewer->value, 'signing_round' => 2],
        ]);
        $first = $this->addLegacyViewer($envelope, 'v1@example.com');
        $second = $this->addLegacyViewer($envelope, 'v2@example.com');

        $this->sign($this->party($envelope, 'r1@example.com'));
        $this->sync();

        // Første blokering løses manuelt.
        unset($this->idura->signatories[$first]);
        $this->party($envelope, 'v1@example.com')->update(['idura_signatory_id' => null]);
        $this->sync();
        $this->assertSame(2, $envelope->fresh()->current_round);

        $this->sign($this->party($envelope, 'r2@example.com'));
        $this->sync();
        $this->sync();

        Exceptions::assertReportedCount(2);
        Exceptions::assertReported(fn (\Fountainhead\SigningRoom\Exceptions\EnvelopeBlockedByViewerException $e) => str_contains($e->getMessage(), "slet viewer-signatory {$second} hos Idura"));
    }

    #[Test]
    public function a_cancelled_idura_order_stops_the_sync_before_any_round_is_advanced(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([
            ['name' => 'R1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'R2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ]);

        // R1 er claimet, men runden blev aldrig rykket; imens er ordren
        // annulleret hos Idura.
        $r1 = $this->party($envelope, 'r1@example.com');
        $r1->update(['status' => SigningPartyStatus::Signed, 'signed_at' => now()]);
        $this->idura->orderStatus = 'CANCELLED';

        $this->sync();
        $this->sync();

        $this->assertSame(1, $envelope->fresh()->current_round);
        Notification::assertNotSentTo($this->party($envelope, 'r2@example.com'), DocumentReadyNotification::class);
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (\Fountainhead\SigningRoom\Exceptions\EnvelopeOrderNotOpenException $e) => str_contains($e->getMessage(), 'er CANCELLED'));
    }

    #[Test]
    public function completion_holds_no_transaction_during_the_idura_call_and_a_concurrent_attempt_backs_off(): void
    {
        $creator = $this->creator();
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']], $creator->id);

        $outside = \Illuminate\Support\Facades\DB::transactionLevel();
        $levelDuringClose = null;
        $concurrentResult = null;

        $this->idura->duringClose = function () use (&$levelDuringClose, &$concurrentResult, $envelope) {
            $levelDuringClose = \Illuminate\Support\Facades\DB::transactionLevel();
            // En anden proces (sync) forsøger samtidig.
            $concurrentResult = $this->service->completeEnvelope($envelope->fresh());
        };

        $this->sign($this->party($envelope, 'a@example.com'));

        $this->assertSame($outside, $levelDuringClose, 'Ingen transaktion/lås må holdes under Idura-kaldet.');
        $this->assertFalse($concurrentResult, 'Den samtidige proces skal trække sig.');
        $this->assertSame(1, $this->idura->closeCalls);
        $this->assertEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
        $this->assertNull($envelope->fresh()->completion_started_at);
        Notification::assertSentToTimes($this->party($envelope, 'a@example.com'), EnvelopeCompletedNotification::class, 1);
        Notification::assertSentToTimes($creator, EnvelopeCompletedNotification::class, 1);
    }

    #[Test]
    public function a_completion_claim_left_by_a_dead_process_is_taken_over_after_ten_minutes(): void
    {
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $a = $this->party($envelope, 'a@example.com');
        $this->idura->markSigned($a->idura_signatory_id);
        $a->update(['status' => SigningPartyStatus::Signed, 'signed_at' => now()]);

        SigningEnvelope::whereKey($envelope->id)->update(['completion_started_at' => now()->subMinutes(2)]);
        $this->sync();
        $this->assertSame(0, $this->idura->closeCalls, 'En frisk claim må ikke overtages.');

        SigningEnvelope::whereKey($envelope->id)->update(['completion_started_at' => now()->subMinutes(11)]);
        $this->sync();

        $this->assertSame(1, $this->idura->closeCalls);
        $this->assertEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
    }

    #[Test]
    public function the_creator_is_not_told_to_wait_for_all_signers_when_all_have_signed(): void
    {
        config(['signing-room.notifications.party_signed' => true]);
        Exceptions::fake();

        $creator = $this->creator();
        $envelope = $this->sentEnvelope([
            ['name' => 'A', 'email' => 'a@example.com'],
            ['name' => 'B', 'email' => 'b@example.com'],
            ['name' => 'Kopi', 'email' => 'viewer@example.com', 'role' => SigningPartyRole::Viewer->value],
        ], $creator->id);
        $this->addLegacyViewer($envelope, 'viewer@example.com');

        $this->sign($this->party($envelope, 'a@example.com'));
        $this->sign($this->party($envelope, 'b@example.com'));

        // A: "1 af 2". B: alle underskrivere er færdige, men kuverten er
        // blokeret af den gamle kopimodtager; ingen "du får besked"-mail.
        Notification::assertSentToTimes($creator, PartySignedNotification::class, 1);
        Notification::assertNotSentTo($creator, EnvelopeCompletedNotification::class);
    }

    #[Test]
    public function one_failing_completion_mail_does_not_stop_the_others(): void
    {
        Exceptions::fake();

        $creator = $this->creator();
        $envelope = $this->sentEnvelope([
            ['name' => 'A', 'email' => 'a@example.com'],
            ['name' => 'B', 'email' => 'b@example.com'],
        ], $creator->id);

        $fake = Notification::getFacadeRoot();
        Notification::swap(new class($fake) extends \Illuminate\Support\Testing\Fakes\NotificationFake {
            public function __construct(private $inner) {}

            public function send($notifiables, $notification)
            {
                $first = collect(\Illuminate\Support\Arr::wrap($notifiables))->first();
                if ($notification instanceof EnvelopeCompletedNotification && ($first->email ?? null) === 'a@example.com') {
                    throw new \RuntimeException('Mail til A fejlede');
                }

                $this->inner->send($notifiables, $notification);
            }
        });

        $this->sign($this->party($envelope, 'a@example.com'));
        $this->sign($this->party($envelope, 'b@example.com'));

        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'Mail til A fejlede');
        $fake->assertSentTo($this->party($envelope, 'b@example.com'), EnvelopeCompletedNotification::class);
        $fake->assertSentTo($creator, EnvelopeCompletedNotification::class);
        $this->assertEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
    }

    #[Test]
    public function a_failing_close_is_reported_once_not_every_hour(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $this->idura->failNextCloses = 3;

        $this->sign($this->party($envelope, 'a@example.com'));
        $this->sync();
        $this->sync();

        $this->assertSame(3, $this->idura->closeCalls);
        Exceptions::assertReportedCount(1);
        $this->assertNull($envelope->fresh()->completion_started_at, 'Claimet frigives efter en fejl.');

        $this->sync();
        $this->assertEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
    }

    #[Test]
    public function the_sync_job_still_completes_when_a_webhook_claimed_the_signature_first(): void
    {
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $a = $this->party($envelope, 'a@example.com');
        $this->idura->markSigned($a->idura_signatory_id);

        // Webhooken når at claime (og dø før fuldførelsen) mellem sync-jobbets
        // opslag af parten og dets eget handleSigned-kald.
        $service = \Mockery::mock(SigningRoomService::class, [$this->idura])->makePartial();
        $service->shouldReceive('handleSigned')->once()->andReturnUsing(function (SigningParty $party) {
            $party->update(['status' => SigningPartyStatus::Signed, 'signed_at' => now()]);

            return false;
        });

        (new SyncIduraSignatureStatus)->handle($this->idura, $service);

        $this->assertSame(1, $this->idura->closeCalls);
        $this->assertEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
    }

    #[Test]
    public function a_legacy_viewer_is_not_reported_while_a_signer_in_the_same_round_is_still_missing(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([
            ['name' => 'A', 'email' => 'a@example.com'],
            ['name' => 'B', 'email' => 'b@example.com'],
            ['name' => 'Kopi', 'email' => 'viewer@example.com', 'role' => SigningPartyRole::Viewer->value],
        ]);
        $this->addLegacyViewer($envelope, 'viewer@example.com');

        $this->sign($this->party($envelope, 'a@example.com'));
        $this->sync();

        // Runden venter også på B: det er ikke en blokering, der kræver handling.
        Exceptions::assertNothingReported();
        $this->assertSame(0, $envelope->events()->where('event_type', 'envelope.blocked')->count());
    }

    #[Test]
    public function the_party_signed_switch_reads_the_env_value_as_a_boolean(): void
    {
        $saved = getenv('SIGNING_ROOM_NOTIFY_PARTY_SIGNED');

        try {
            foreach (['no' => false, 'off' => false, '0' => false, 'yes' => true, '1' => true, 'true' => true] as $raw => $expected) {
                putenv("SIGNING_ROOM_NOTIFY_PARTY_SIGNED={$raw}");
                $config = require __DIR__ . '/../../config/signing-room.php';
                $this->assertSame($expected, $config['notifications']['party_signed'], "SIGNING_ROOM_NOTIFY_PARTY_SIGNED={$raw}");
            }

            putenv('SIGNING_ROOM_NOTIFY_PARTY_SIGNED');
            $config = require __DIR__ . '/../../config/signing-room.php';
            $this->assertFalse($config['notifications']['party_signed']);
        } finally {
            putenv($saved === false ? 'SIGNING_ROOM_NOTIFY_PARTY_SIGNED' : "SIGNING_ROOM_NOTIFY_PARTY_SIGNED={$saved}");
        }
    }

    #[Test]
    public function a_cancelled_order_records_signatures_but_advances_nothing(): void
    {
        \Illuminate\Support\Facades\Event::fake([\Fountainhead\SigningRoom\Events\PartySignedEvent::class]);
        Exceptions::fake();

        $envelope = $this->sentEnvelope([
            ['name' => 'R1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'R2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ]);
        $r1 = $this->party($envelope, 'r1@example.com');
        $this->idura->markSigned($r1->idura_signatory_id);
        $this->idura->orderStatus = 'CANCELLED';

        $this->sync();
        $this->sync();

        // Underskriften blev givet før annulleringen: den registreres (én gang).
        $this->assertEquals(SigningPartyStatus::Signed, $r1->fresh()->status);
        $this->assertSame(1, $envelope->events()->where('event_type', 'party.signed')->count());
        \Illuminate\Support\Facades\Event::assertDispatchedTimes(\Fountainhead\SigningRoom\Events\PartySignedEvent::class, 1);
        $this->assertSame(1, $envelope->fresh()->current_round);
        Notification::assertNotSentTo($this->party($envelope, 'r2@example.com'), DocumentReadyNotification::class);
        Exceptions::assertReportedCount(1);
    }

    #[Test]
    public function an_expired_order_is_marked_expired_and_records_signatures_made_before_expiry(): void
    {
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $a = $this->party($envelope, 'a@example.com');
        $this->idura->markSigned($a->idura_signatory_id);
        $this->idura->orderStatus = 'EXPIRED';

        $this->sync();

        $this->assertEquals(EnvelopeStatus::Expired, $envelope->fresh()->status);
        $this->assertEquals(SigningPartyStatus::Signed, $a->fresh()->status, 'Underskriften fra før udløb skal registreres.');
        $this->assertSame(1, $envelope->events()->where('event_type', 'party.signed')->count());
        $this->assertSame(0, $this->idura->closeCalls);
        Notification::assertNotSentTo($a, EnvelopeCompletedNotification::class);
    }

    #[Test]
    public function a_signature_on_a_cancelled_envelope_is_recorded_but_nothing_advances(): void
    {
        $envelope = $this->sentEnvelope([
            ['name' => 'R1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'R2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ]);
        SigningEnvelope::whereKey($envelope->id)->update(['status' => EnvelopeStatus::Cancelled->value]);

        $this->sign($this->party($envelope, 'r1@example.com'));

        $this->assertEquals(SigningPartyStatus::Signed, $this->party($envelope, 'r1@example.com')->status);
        $this->assertSame(1, $envelope->fresh()->current_round);
        $this->assertEquals(EnvelopeStatus::Cancelled, $envelope->fresh()->status);
        Notification::assertNotSentTo($this->party($envelope, 'r2@example.com'), DocumentReadyNotification::class);
    }

    #[Test]
    public function the_process_that_loses_the_completion_race_neither_logs_nor_mails(): void
    {
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);

        // Imens vi lukker ordren, overtager en anden proces og fuldfører først.
        $this->idura->duringClose = function () use ($envelope) {
            SigningEnvelope::whereKey($envelope->id)->update([
                'status' => EnvelopeStatus::Completed->value,
                'completed_at' => now(),
                'signed_document' => 'signing-room/other.pdf',
                'completion_started_at' => null,
            ]);
        };

        $this->sign($this->party($envelope, 'a@example.com'));

        $this->assertSame(0, $envelope->events()->where('event_type', 'envelope.completed')->count());
        Notification::assertNotSentTo($this->party($envelope, 'a@example.com'), EnvelopeCompletedNotification::class);
        $this->assertSame('signing-room/other.pdf', $envelope->fresh()->signed_document);
    }

    #[Test]
    public function a_failing_process_only_releases_its_own_claim(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $theirs = now()->addMinute()->startOfSecond();

        // Vores claim blev overtaget, mens vi ventede på Idura; derefter fejler vi.
        $this->idura->failNextCloses = 1;
        $this->idura->duringClose = function () use ($envelope, $theirs) {
            SigningEnvelope::whereKey($envelope->id)->update(['completion_started_at' => $theirs]);
        };

        $this->sign($this->party($envelope, 'a@example.com'));

        $this->assertEquals($theirs, $envelope->fresh()->completion_started_at, 'En andens claim må ikke slettes.');
    }

    #[Test]
    public function varying_failure_messages_are_reported_once_and_resolved_on_success(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $this->idura->failNextCloses = 2; // "request 1", "request 2": forskellige beskeder

        $this->sign($this->party($envelope, 'a@example.com'));
        $this->sync();

        Exceptions::assertReportedCount(1);
        $this->assertSame(0, $envelope->events()->where('event_type', 'envelope.unblocked')->count());

        $this->sync();

        $this->assertEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
        $this->assertSame(1, $envelope->events()->where('event_type', 'envelope.unblocked')->count(), 'En forbigående fejl må ikke stå som åben blokering.');
    }

    #[Test]
    public function a_failed_round_mail_is_retried_by_the_sync_job_without_mailing_others_twice(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([
            ['name' => 'R1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'R2a', 'email' => 'r2a@example.com', 'signing_round' => 2],
            ['name' => 'R2b', 'email' => 'r2b@example.com', 'signing_round' => 2],
        ]);

        $fake = Notification::getFacadeRoot();
        $failing = new class($fake) extends \Illuminate\Support\Testing\Fakes\NotificationFake {
            public bool $fail = true;

            public function __construct(private $inner) {}

            public function send($notifiables, $notification)
            {
                $first = collect(\Illuminate\Support\Arr::wrap($notifiables))->first();
                if ($this->fail && $notification instanceof DocumentReadyNotification && ($first->email ?? null) === 'r2a@example.com') {
                    throw new \RuntimeException('Mailkø nede');
                }

                $this->inner->send($notifiables, $notification);
            }
        };
        Notification::swap($failing);

        $this->sign($this->party($envelope, 'r1@example.com'));

        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'Mailkø nede');
        $this->assertSame(2, $envelope->fresh()->current_round);
        $this->assertNull($this->party($envelope, 'r2a@example.com')->notified_at);
        $fake->assertSentToTimes($this->party($envelope, 'r2b@example.com'), DocumentReadyNotification::class, 1);

        $failing->fail = false;
        $this->sync();
        $this->sync();

        $fake->assertSentToTimes($this->party($envelope, 'r2a@example.com'), DocumentReadyNotification::class, 1);
        $fake->assertSentToTimes($this->party($envelope, 'r2b@example.com'), DocumentReadyNotification::class, 1);
    }

    #[Test]
    public function the_signer_check_accepts_the_role_enum_and_any_case(): void
    {
        $envelope = $this->service->createEnvelope(
            title: 'Enum-rolle',
            pdfPath: 'signing-room/original.pdf',
            parties: [
                ['name' => 'A', 'email' => 'a@example.com', 'role' => SigningPartyRole::Signer],
                ['name' => 'Kopi', 'email' => 'v@example.com', 'role' => SigningPartyRole::Viewer],
            ],
        );
        $this->assertSame(['signer', 'viewer'], $envelope->parties()->orderBy('id')->pluck('role')->all());

        $upper = $this->service->createEnvelope(
            title: 'Store bogstaver',
            pdfPath: 'signing-room/original.pdf',
            parties: [['name' => 'B', 'email' => 'b@example.com', 'role' => 'SIGNER']],
        );
        $this->assertSame(['signer'], $upper->parties()->pluck('role')->all());
    }

    #[Test]
    public function the_backfill_skips_old_signatures_and_flags_the_event(): void
    {
        \Illuminate\Support\Facades\Event::fake([\Fountainhead\SigningRoom\Events\PartySignedEvent::class]);

        $envelope = $this->sentEnvelope([
            ['name' => 'Gammel', 'email' => 'old@example.com', 'signing_round' => 1],
            ['name' => 'Nylig', 'email' => 'recent@example.com', 'signing_round' => 1],
            ['name' => 'R2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ]);
        foreach (['old@example.com' => 60 * 30, 'recent@example.com' => 10] as $email => $minutesAgo) {
            $party = $this->party($envelope, $email);
            $this->idura->markSigned($party->idura_signatory_id);
            $party->update(['status' => SigningPartyStatus::Signed, 'signed_at' => now()->subMinutes($minutesAgo)]);
        }

        $this->sync();

        \Illuminate\Support\Facades\Event::assertDispatchedTimes(\Fountainhead\SigningRoom\Events\PartySignedEvent::class, 1);
        \Illuminate\Support\Facades\Event::assertDispatched(\Fountainhead\SigningRoom\Events\PartySignedEvent::class, fn ($e) => $e->party->email === 'recent@example.com' && $e->backfilled === true);
    }

    #[Test]
    public function a_failing_host_listener_does_not_lose_the_signature_or_the_round(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([
            ['name' => 'R1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'R2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ]);

        \Illuminate\Support\Facades\Event::listen(\Fountainhead\SigningRoom\Events\PartySignedEvent::class, function () {
            throw new \RuntimeException('Host-listener fejlede');
        });

        $this->sign($this->party($envelope, 'r1@example.com'));

        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'Host-listener fejlede');
        $this->assertSame(2, $envelope->fresh()->current_round, 'Underskriften skal behandles færdig.');
        Notification::assertSentTo($this->party($envelope, 'r2@example.com'), DocumentReadyNotification::class);
    }

    #[Test]
    public function the_signed_event_goes_through_the_queue_so_a_failure_can_be_retried(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com'], ['name' => 'B', 'email' => 'b@example.com']]);
        $this->sign($this->party($envelope, 'a@example.com'));

        \Illuminate\Support\Facades\Queue::assertPushed(\Fountainhead\SigningRoom\Jobs\DispatchPartySignedEvent::class, fn ($job) => $job->party->email === 'a@example.com'
            && $job->queue === null
            && $job->tries === 1);
    }

    #[Test]
    public function different_failures_of_the_same_class_get_their_own_report_but_varying_ids_do_not(): void
    {
        $diskFailure = new \RuntimeException('Kunne ikke gemme signeret PDF for kuvert 9f1c2d3e-0000-4000-8000-000000000001 på sti signing-room/9f1c2d3e-0000-4000-8000-000000000001/signed.pdf.');
        $otherDisk = new \RuntimeException('Kunne ikke gemme signeret PDF for kuvert 0a1b2c3d-1111-4000-8000-000000000002 på sti signing-room/0a1b2c3d-1111-4000-8000-000000000002/signed.pdf.');
        $somethingElse = new \RuntimeException('Uventet svar fra Idura: dokumentliste mangler');

        $this->assertSame(SigningRoomService::completionFailureReason($diskFailure), SigningRoomService::completionFailureReason($otherDisk));
        $this->assertNotSame(SigningRoomService::completionFailureReason($diskFailure), SigningRoomService::completionFailureReason($somethingElse));

        $a = new \RuntimeException('Idura API request failed with status 503: unavailable (request 1)');
        $b = new \RuntimeException('Idura API request failed with status 503: unavailable (request 2)');
        $this->assertSame(SigningRoomService::completionFailureReason($a), SigningRoomService::completionFailureReason($b));
    }

    #[Test]
    public function an_order_that_expires_after_everyone_signed_is_reported_not_silently_expired(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $this->idura->failNextCloses = 99; // fuldførelsen bliver ved med at fejle

        $this->sign($this->party($envelope, 'a@example.com'));
        $this->idura->orderStatus = 'EXPIRED';
        $this->sync();
        $this->sync();

        $this->assertEquals(EnvelopeStatus::Expired, $envelope->fresh()->status);
        Exceptions::assertReported(fn (\Fountainhead\SigningRoom\Exceptions\EnvelopeOrderNotOpenException $e) => str_contains($e->getMessage(), 'udløb, før kuverten blev fuldført'));
        $this->assertSame(1, $envelope->events()->where('event_type', 'envelope.blocked')->get()
            ->filter(fn ($e) => ($e->metadata['reason'] ?? '') === 'idura_order_expired:all_signed')->count());
    }

    #[Test]
    public function an_expired_order_with_a_signer_still_missing_is_not_reported_as_lost(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com'], ['name' => 'B', 'email' => 'b@example.com']]);
        $this->sign($this->party($envelope, 'a@example.com'));
        $this->idura->orderStatus = 'EXPIRED';
        $this->sync();

        Exceptions::assertNothingReported();
        $this->assertEquals(EnvelopeStatus::Expired, $envelope->fresh()->status);
    }

    #[Test]
    public function an_order_idura_no_longer_knows_is_reported_once(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $this->idura->orderMissing = true;

        $this->sync();
        $this->sync();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (\Fountainhead\SigningRoom\Exceptions\EnvelopeOrderNotOpenException $e) => str_contains($e->getMessage(), 'returnerer ikke ordren'));
    }

    #[Test]
    public function a_resolved_block_is_shown_green_in_the_admin_timeline(): void
    {
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $envelope->logEvent(\Fountainhead\SigningRoom\Enums\SigningEventType::EnvelopeBlocked, metadata: ['reason' => 'x']);
        $envelope->logEvent(\Fountainhead\SigningRoom\Enums\SigningEventType::EnvelopeUnblocked);

        $component = new \Fountainhead\SigningRoom\Livewire\Admin\EnvelopeShow;
        $component->mount($envelope);
        // Livewire giver viewet komponentens offentlige egenskaber; her direkte.
        $html = view('signing-room::admin.envelope-show', get_object_vars($component))->render();

        $this->assertStringContainsString('Blokering løst', $html);
        // Prikken før "Blokering løst" er grøn, ikke rød.
        $pos = strpos($html, 'Blokering løst');
        $before = substr($html, max(0, $pos - 1500), 1500);
        $lastGreen = strrpos($before, 'var(--ft-green)');
        $lastRed = strrpos($before, 'var(--ft-red)');
        $this->assertNotFalse($lastGreen);
        $this->assertTrue($lastRed === false || $lastGreen > $lastRed, 'Prikken ved "Blokering løst" skal være grøn.');
    }

    #[Test]
    public function the_scheduled_jobs_use_the_default_queue_unless_the_host_chooses_one(): void
    {
        foreach ([SyncIduraSignatureStatus::class, \Fountainhead\SigningRoom\Jobs\SendSigningReminders::class, \Fountainhead\SigningRoom\Jobs\ExpireSigningEnvelopes::class] as $job) {
            $this->assertNull((new $job)->queue, "{$job} skal på standardkøen");
        }

        config(['signing-room.queue' => 'signing-room']);
        $this->assertSame('signing-room', (new SyncIduraSignatureStatus)->queue);
    }

    #[Test]
    public function an_http_failure_key_ignores_the_response_body(): void
    {
        $a = new \RuntimeException('Idura API request failed with status 502: <html>req_Ab3kZ9 nonce=xY</html>');
        $b = new \RuntimeException('Idura API request failed with status 502: <html>req_Q7pL nonce=zW</html>');
        $c = new \RuntimeException('Idura API request failed with status 503: unavailable');

        $this->assertSame(SigningRoomService::completionFailureReason($a), SigningRoomService::completionFailureReason($b));
        $this->assertNotSame(SigningRoomService::completionFailureReason($a), SigningRoomService::completionFailureReason($c));
    }

    #[Test]
    public function a_closed_order_without_a_pdf_is_not_completed_and_is_reported_as_critical(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $this->idura->closeWithoutBlob = true;

        $this->sign($this->party($envelope, 'a@example.com'));

        $this->assertNotEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
        $this->assertNull($envelope->fresh()->completed_at);
        Exceptions::assertReported(fn (\Fountainhead\SigningRoom\Exceptions\SignedDocumentUnavailableException $e) => str_starts_with($e->getMessage(), 'KRITISK:'));
        Notification::assertNotSentTo($this->party($envelope, 'a@example.com'), EnvelopeCompletedNotification::class);
    }

    #[Test]
    public function the_expired_webhook_reports_an_envelope_that_everyone_signed_but_never_completed(): void
    {
        Exceptions::fake();
        config(['signing-room.idura.webhook_secret' => null]);

        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $this->idura->failNextCloses = 99;
        $this->sign($this->party($envelope, 'a@example.com'));

        $this->postJson(route('signing-room.webhook'), [
            'event' => 'SIGNATURE_ORDER_EXPIRED',
            'signatureOrderId' => $envelope->idura_signature_order_id,
        ])->assertOk();

        $this->assertEquals(EnvelopeStatus::Expired, $envelope->fresh()->status);
        Exceptions::assertReported(fn (\Fountainhead\SigningRoom\Exceptions\EnvelopeOrderNotOpenException $e) => str_contains($e->getMessage(), 'udløb, før kuverten blev fuldført'));
    }

    #[Test]
    public function the_daily_expiry_job_does_not_expire_a_completed_envelope_and_reports_a_lost_one(): void
    {
        Exceptions::fake();

        $done = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $this->sign($this->party($done, 'a@example.com'));
        // Fuldført, men status overskrevet; udløbsdatoen er passeret.
        SigningEnvelope::whereKey($done->id)->update(['status' => EnvelopeStatus::PartiallySigned->value, 'expires_at' => now()->subDay()]);

        // FakeIdura har én ordre; den første kuvert lukkede den.
        $this->idura->orderStatus = 'OPEN';
        $lost = $this->sentEnvelope([['name' => 'B', 'email' => 'b@example.com']]);
        $this->idura->failNextCloses = 99;
        $this->sign($this->party($lost, 'b@example.com'));
        SigningEnvelope::whereKey($lost->id)->update(['expires_at' => now()->subDay()]);

        $this->app->call([new \Fountainhead\SigningRoom\Jobs\ExpireSigningEnvelopes, 'handle']);

        $this->assertEquals(EnvelopeStatus::Completed, $done->fresh()->status);
        $this->assertEquals(EnvelopeStatus::Expired, $lost->fresh()->status);
        Exceptions::assertReported(fn (\Fountainhead\SigningRoom\Exceptions\EnvelopeOrderNotOpenException $e) => str_contains($e->getMessage(), "Kuvert {$lost->id} "));
    }

    #[Test]
    public function a_signature_found_by_polling_is_flagged_as_backfilled(): void
    {
        \Illuminate\Support\Facades\Event::fake([\Fountainhead\SigningRoom\Events\PartySignedEvent::class]);

        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com'], ['name' => 'B', 'email' => 'b@example.com']]);
        $this->idura->markSigned($this->party($envelope, 'a@example.com')->idura_signatory_id);

        $this->sync();

        \Illuminate\Support\Facades\Event::assertDispatched(\Fountainhead\SigningRoom\Events\PartySignedEvent::class, fn ($e) => $e->party->email === 'a@example.com' && $e->backfilled === true);
    }

    #[Test]
    public function the_scheduled_jobs_leave_envelopes_created_before_the_cutoff_alone(): void
    {
        $old = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        SigningEnvelope::whereKey($old->id)->update(['created_at' => now()->subMonths(3)]);
        config(['signing-room.process_envelopes_created_since' => now()->subWeek()->toDateString()]);

        $this->idura->markSigned($this->party($old, 'a@example.com')->idura_signatory_id);
        $calls = $this->idura->orderStatusCalls;
        $this->sync();
        $this->assertSame($calls, $this->idura->orderStatusCalls, 'Sync må ikke røre en kuvert fra før grænsen.');

        SigningEnvelope::whereKey($old->id)->update(['expires_at' => now()->subDay()]);
        $this->app->call([new \Fountainhead\SigningRoom\Jobs\ExpireSigningEnvelopes, 'handle']);
        $this->assertNotEquals(EnvelopeStatus::Expired, $old->fresh()->status);

        $this->assertSame(0, SigningEnvelope::query()->tap(fn ($q) => SigningEnvelope::onlyProcessable($q))->whereKey($old->id)->count());
    }

    #[Test]
    public function two_processes_notifying_the_same_round_send_each_mail_once(): void
    {
        $envelope = $this->sentEnvelope([
            ['name' => 'R1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'R2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ]);

        // Mens mailen til R2 lægges i kø, når en anden proces (sync) samme runde.
        $fake = Notification::getFacadeRoot();
        $service = $this->service;
        $reentered = false;
        Notification::swap(new class($fake, $service, $envelope, $reentered) extends \Illuminate\Support\Testing\Fakes\NotificationFake {
            public function __construct(private $inner, private $service, private $envelope, private bool &$reentered) {}

            public function send($notifiables, $notification)
            {
                if ($notification instanceof DocumentReadyNotification && ! $this->reentered) {
                    $this->reentered = true;
                    $this->service->notifyRoundParties($this->envelope->fresh(), 2);
                }

                $this->inner->send($notifiables, $notification);
            }
        });

        $this->sign($this->party($envelope, 'r1@example.com'));

        $fake->assertSentToTimes($this->party($envelope, 'r2@example.com'), DocumentReadyNotification::class, 1);
    }

    #[Test]
    public function the_signature_data_is_written_in_the_same_update_as_the_claim(): void
    {
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com'], ['name' => 'B', 'email' => 'b@example.com']]);
        $a = $this->party($envelope, 'a@example.com');

        $claimSql = null;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$claimSql) {
            if ($claimSql === null && str_starts_with($query->sql, 'update "signing_parties"') && str_contains($query->sql, '"status" = ?')) {
                $claimSql = $query->sql;
            }
        });

        $this->service->handleSigned($a, ['event' => 'SIGNATORY_SIGNED', 'x' => 1]);

        $this->assertNotNull($claimSql);
        $this->assertStringContainsString('"signature_data"', $claimSql);
        $this->assertSame(['event' => 'SIGNATORY_SIGNED', 'x' => 1], $a->fresh()->signature_data);
    }

    #[Test]
    public function a_failing_sync_for_one_envelope_is_reported_once_not_every_hour(): void
    {
        Exceptions::fake();

        $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $this->idura->orderStatusFailure = new \RuntimeException('Idura API request failed with status 401: unauthorized (trace '.uniqid().')');

        $this->sync();
        $this->sync();

        Exceptions::assertReportedCount(1);
    }
}
