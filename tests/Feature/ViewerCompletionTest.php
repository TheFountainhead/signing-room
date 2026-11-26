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
            ->filter(fn ($e) => ($e->metadata['reason'] ?? '') === 'idura_order_expired')->count());
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
    public function the_daily_expiry_job_neither_expires_a_completed_envelope_nor_an_all_signed_one_it_could_not_complete(): void
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
        // Alle har underskrevet, og Idura-ordren er stadig åben: kuverten
        // udløber ikke lokalt, så sync kan fuldføre den senere.
        $this->assertNotEquals(EnvelopeStatus::Expired, $lost->fresh()->status);
        $this->assertSame(0, $lost->events()->where('event_type', 'envelope.expired')->count());
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

    #[Test]
    public function a_polled_signature_of_unknown_age_is_recorded_without_the_event(): void
    {
        \Illuminate\Support\Facades\Event::fake([\Fountainhead\SigningRoom\Events\PartySignedEvent::class]);
        \Illuminate\Support\Facades\Log::spy();

        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com'], ['name' => 'B', 'email' => 'b@example.com']]);
        $a = $this->party($envelope, 'a@example.com');
        // Gammel kuvert, aldrig synkroniseret før (fx første kørsel hos en host).
        SigningEnvelope::whereKey($envelope->id)->update(['created_at' => now()->subDays(40), 'last_synced_at' => null]);
        $this->idura->markSigned($a->idura_signatory_id);

        $this->sync();

        $this->assertEquals(SigningPartyStatus::Signed, $a->fresh()->status);
        \Illuminate\Support\Facades\Event::assertNotDispatched(\Fountainhead\SigningRoom\Events\PartySignedEvent::class);
        $event = $envelope->events()->where('event_type', 'party.signed')->where('signing_party_id', $a->id)->first();
        $this->assertSame('unknown_age', $event->metadata['event_skipped'] ?? null);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'uden PartySignedEvent'));
    }

    #[Test]
    public function the_daily_expiry_job_completes_an_all_signed_envelope_whose_idura_order_is_still_open(): void
    {
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $this->idura->failNextCloses = 1; // første forsøg fejler; ordren er stadig åben
        $this->sign($this->party($envelope, 'a@example.com'));
        SigningEnvelope::whereKey($envelope->id)->update(['expires_at' => now()->subDay()]);

        $this->app->call([new \Fountainhead\SigningRoom\Jobs\ExpireSigningEnvelopes, 'handle']);

        $this->assertEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
        Notification::assertSentTo($this->party($envelope, 'a@example.com'), EnvelopeCompletedNotification::class);
    }

    #[Test]
    public function an_expiry_does_not_overwrite_a_completion_that_finishes_at_the_same_time(): void
    {
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com'], ['name' => 'B', 'email' => 'b@example.com']]);

        // Mens expireEnvelope kigger på parterne, fuldfører en anden proces.
        $done = false;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$done, $envelope) {
            if (! $done && str_contains($query->sql, 'from "signing_parties"')) {
                $done = true;
                \Illuminate\Support\Facades\DB::table('signing_envelopes')->where('id', $envelope->id)->update([
                    'status' => EnvelopeStatus::Completed->value,
                    'completed_at' => now(),
                    'signed_document' => 'signing-room/x.pdf',
                ]);
            }
        });

        $this->assertFalse($this->service->expireEnvelope($envelope));
        $this->assertEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
        $this->assertSame(0, $envelope->events()->where('event_type', 'envelope.expired')->count());
    }

    #[Test]
    public function a_sync_failure_is_resolved_by_the_next_successful_sync_and_a_new_one_is_reported_again(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com'], ['name' => 'B', 'email' => 'b@example.com']]);

        $this->idura->orderStatusFailure = new \RuntimeException('Idura API request failed with status 503: nede');
        $this->sync();
        $this->idura->orderStatusFailure = null;
        $this->sync();

        $this->assertSame(1, $envelope->events()->where('event_type', 'envelope.unblocked')->count());

        $this->idura->orderStatusFailure = new \RuntimeException('Idura API request failed with status 503: nede igen');
        $this->sync();

        Exceptions::assertReportedCount(2);
    }

    #[Test]
    public function an_invalid_cutoff_date_processes_nothing_and_is_logged(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        config(['signing-room.process_envelopes_created_since' => '26/11-2026']);

        $calls = $this->idura->orderStatusCalls;
        $this->sync();

        $this->assertSame($calls, $this->idura->orderStatusCalls);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'ikke en gyldig dato'));
    }

    #[Test]
    public function a_webhook_for_an_unknown_signatory_is_ignored_not_a_500(): void
    {
        config(['signing-room.idura.webhook_secret' => null]);
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);

        $this->postJson(route('signing-room.webhook'), [
            'event' => 'SIGNATORY_SIGNED',
            'signatureOrderId' => $envelope->idura_signature_order_id,
            'signatoryId' => 'Signatory:deleted-legacy-viewer',
        ])->assertOk()->assertJson(['status' => 'ignored']);
    }

    #[Test]
    public function resolving_a_sync_failure_leaves_other_blocks_open(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([
            ['name' => 'A', 'email' => 'a@example.com'],
            ['name' => 'Kopi', 'email' => 'viewer@example.com', 'role' => SigningPartyRole::Viewer->value],
        ]);
        $this->addLegacyViewer($envelope, 'viewer@example.com');
        $this->sign($this->party($envelope, 'a@example.com'));

        $this->sync(); // gammel kopimodtager: rapport 1
        $this->idura->orderStatusFailure = new \RuntimeException('Idura API request failed with status 503: nede');
        $this->sync(); // sync-fejl: rapport 2
        $this->idura->orderStatusFailure = null;
        $this->sync(); // lykkes: kun sync-fejlen løses
        $this->sync();

        Exceptions::assertReportedCount(2);
    }

    #[Test]
    public function a_polled_signature_after_a_recent_successful_sync_fires_the_event(): void
    {
        \Illuminate\Support\Facades\Event::fake([\Fountainhead\SigningRoom\Events\PartySignedEvent::class]);

        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com'], ['name' => 'B', 'email' => 'b@example.com']]);
        SigningEnvelope::whereKey($envelope->id)->update(['created_at' => now()->subDays(40)]);

        $this->sync(); // vellykket sync: A er stadig usigneret, last_synced_at sættes
        $this->assertNotNull($envelope->fresh()->last_synced_at);

        // A underskriver (fx dag 3), webhooken går tabt; næste sync finder den.
        $this->idura->markSigned($this->party($envelope, 'a@example.com')->idura_signatory_id);
        $this->sync();

        \Illuminate\Support\Facades\Event::assertDispatched(\Fountainhead\SigningRoom\Events\PartySignedEvent::class, fn ($e) => $e->party->email === 'a@example.com');
    }

    #[Test]
    public function the_daily_expiry_job_leaves_an_envelope_being_completed_alone(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $a = $this->party($envelope, 'a@example.com');
        $a->update(['status' => SigningPartyStatus::Signed, 'signed_at' => now()]);
        // En webhook er midt i fuldførelsen (frisk claim).
        SigningEnvelope::whereKey($envelope->id)->update(['completion_started_at' => now(), 'expires_at' => now()->subMinute()]);

        $this->app->call([new \Fountainhead\SigningRoom\Jobs\ExpireSigningEnvelopes, 'handle']);

        $this->assertNotEquals(EnvelopeStatus::Expired, $envelope->fresh()->status);
        $this->assertSame(0, $this->idura->closeCalls);
        Exceptions::assertNothingReported();
    }

    #[Test]
    public function an_idura_expiry_during_a_running_completion_is_not_reported_as_lost(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $this->party($envelope, 'a@example.com')->update(['status' => SigningPartyStatus::Signed, 'signed_at' => now()]);
        SigningEnvelope::whereKey($envelope->id)->update(['completion_started_at' => now()]);

        $this->assertFalse($this->service->expireEnvelope($envelope, iduraExpired: true));

        Exceptions::assertNothingReported();
        $this->assertNotEquals(EnvelopeStatus::Expired, $envelope->fresh()->status);
    }

    #[Test]
    public function the_backfill_of_a_polled_claim_follows_the_same_age_rule(): void
    {
        \Illuminate\Support\Facades\Event::fake([\Fountainhead\SigningRoom\Events\PartySignedEvent::class]);

        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com'], ['name' => 'B', 'email' => 'b@example.com']]);
        SigningEnvelope::whereKey($envelope->id)->update(['created_at' => now()->subDays(40), 'last_synced_at' => null]);

        // Polling claimede for 10 min siden og døde før party.signed.
        $a = $this->party($envelope, 'a@example.com');
        $this->idura->markSigned($a->idura_signatory_id);
        $a->forceFill(['status' => SigningPartyStatus::Signed, 'signed_at' => now()->subMinutes(10), 'signature_data' => ['synced_from_polling' => true]])->save();

        $this->sync();

        \Illuminate\Support\Facades\Event::assertNotDispatched(\Fountainhead\SigningRoom\Events\PartySignedEvent::class);
        $this->assertSame(1, $envelope->events()->where('event_type', 'party.signed')->where('signing_party_id', $a->id)->count());
    }

    #[Test]
    public function a_polled_signature_of_unknown_age_does_not_mail_the_creator_either(): void
    {
        config(['signing-room.notifications.party_signed' => true]);
        $creator = $this->creator();

        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com'], ['name' => 'B', 'email' => 'b@example.com']], $creator->id);
        SigningEnvelope::whereKey($envelope->id)->update(['created_at' => now()->subDays(40), 'last_synced_at' => null]);
        $this->idura->markSigned($this->party($envelope, 'a@example.com')->idura_signatory_id);

        $this->sync();

        Notification::assertNotSentTo($creator, PartySignedNotification::class);
    }

    #[Test]
    public function after_a_rejection_the_creator_is_not_told_to_wait_for_all_signatures(): void
    {
        config(['signing-room.notifications.party_signed' => true]);
        $creator = $this->creator();

        $envelope = $this->sentEnvelope([
            ['name' => 'A', 'email' => 'a@example.com'],
            ['name' => 'B', 'email' => 'b@example.com'],
            ['name' => 'C', 'email' => 'c@example.com'],
        ], $creator->id);

        $this->service->handleRejected($this->party($envelope, 'a@example.com'), 'Nej');
        $this->sign($this->party($envelope, 'b@example.com'));

        Notification::assertNotSentTo($creator, PartySignedNotification::class);
    }

    #[Test]
    public function the_sync_job_cannot_overlap_and_the_event_job_uses_the_package_queue(): void
    {
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class, new SyncIduraSignatureStatus);

        config(['signing-room.queue' => 'signing-room']);
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $job = new \Fountainhead\SigningRoom\Jobs\DispatchPartySignedEvent($envelope, $this->party($envelope, 'a@example.com'));

        $this->assertSame('signing-room', $job->queue);
    }

    #[Test]
    public function a_next_round_signer_who_already_opened_the_document_is_still_mailed(): void
    {
        $envelope = $this->sentEnvelope([
            ['name' => 'R1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'R2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ]);
        // R2 kigger i portalen, før runde 2 starter.
        $this->party($envelope, 'r2@example.com')->update(['status' => SigningPartyStatus::Viewed, 'viewed_at' => now()]);

        $this->sign($this->party($envelope, 'r1@example.com'));

        Notification::assertSentToTimes($this->party($envelope, 'r2@example.com'), DocumentReadyNotification::class, 1);
    }

    #[Test]
    public function a_legacy_viewer_blocked_envelope_with_all_signatures_does_not_expire_locally_and_is_reported_on_idura_expiry(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([
            ['name' => 'A', 'email' => 'a@example.com'],
            ['name' => 'Kopi', 'email' => 'viewer@example.com', 'role' => SigningPartyRole::Viewer->value],
        ]);
        $this->addLegacyViewer($envelope, 'viewer@example.com');
        $this->sign($this->party($envelope, 'a@example.com'));

        SigningEnvelope::whereKey($envelope->id)->update(['expires_at' => now()->subDay()]);
        $this->app->call([new \Fountainhead\SigningRoom\Jobs\ExpireSigningEnvelopes, 'handle']);
        $this->assertNotEquals(EnvelopeStatus::Expired, $envelope->fresh()->status, 'Underskrifterne findes; kuverten må ikke udløbe stille lokalt.');

        $this->assertFalse($this->service->expireEnvelope($envelope, iduraExpired: false));
        $this->assertTrue($this->service->expireEnvelope($envelope, iduraExpired: true));
        Exceptions::assertReported(fn (\Fountainhead\SigningRoom\Exceptions\EnvelopeOrderNotOpenException $e) => str_contains($e->getMessage(), 'udløb, før kuverten blev fuldført'));
    }

    #[Test]
    public function a_sync_that_never_saw_the_signatories_does_not_count_as_a_recent_sync(): void
    {
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $this->idura->orderMissing = true;

        $this->sync();

        $this->assertNull($envelope->fresh()->last_synced_at);

        $this->idura->orderMissing = false;
        $this->sync();
        $this->assertNotNull($envelope->fresh()->last_synced_at);
    }

    #[Test]
    public function the_admin_form_treats_an_empty_or_capitalised_role_as_signer_like_the_service(): void
    {
        $hasSigner = new \ReflectionMethod(\Fountainhead\SigningRoom\Livewire\Admin\EnvelopeCreate::class, 'hasSigner');

        foreach (['', 'Signer', 'SIGNER', 'signer'] as $role) {
            $component = new \Fountainhead\SigningRoom\Livewire\Admin\EnvelopeCreate;
            $component->parties = [['name' => 'A', 'email' => 'a@example.com', 'role' => $role, 'signing_round' => 1]];
            $this->assertTrue($hasSigner->invoke($component), "rolle '{$role}'");
        }

        $component = new \Fountainhead\SigningRoom\Livewire\Admin\EnvelopeCreate;
        $component->parties = [['name' => 'A', 'email' => 'a@example.com', 'role' => 'viewer', 'signing_round' => 1]];
        $this->assertFalse($hasSigner->invoke($component));
    }

    #[Test]
    public function a_failed_round_mail_restores_the_party_status_it_had(): void
    {
        Exceptions::fake();

        $envelope = $this->sentEnvelope([
            ['name' => 'R1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'R2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ]);
        $this->party($envelope, 'r2@example.com')->update(['status' => SigningPartyStatus::Viewed, 'viewed_at' => now()]);

        $fake = Notification::getFacadeRoot();
        Notification::swap(new class($fake) extends \Illuminate\Support\Testing\Fakes\NotificationFake {
            public function __construct(private $inner) {}

            public function send($notifiables, $notification)
            {
                if ($notification instanceof DocumentReadyNotification) {
                    throw new \RuntimeException('Mailkø nede');
                }

                $this->inner->send($notifiables, $notification);
            }
        });

        $this->sign($this->party($envelope, 'r1@example.com'));

        $r2 = $this->party($envelope, 'r2@example.com');
        $this->assertEquals(SigningPartyStatus::Viewed, $r2->status);
        $this->assertNull($r2->notified_at);
    }

    #[Test]
    public function a_manual_reminder_cannot_be_sent_to_a_party_whose_round_has_not_started(): void
    {
        $envelope = $this->sentEnvelope([
            ['name' => 'R1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'R2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ]);
        $r2 = $this->party($envelope, 'r2@example.com');

        $component = new \Fountainhead\SigningRoom\Livewire\Admin\EnvelopeShow;
        $component->mount($envelope);
        $component->sendReminder($r2->id);

        $this->assertNull($r2->fresh()->notified_at, 'notified_at må ikke sættes før partens runde.');
        $this->assertStringContainsString('Der kan ikke sendes en påmindelse', (string) session('error'));

        $this->sign($this->party($envelope, 'r1@example.com'));
        Notification::assertSentToTimes($r2, DocumentReadyNotification::class, 1);

        // Nu er det R2's tur: påmindelsen er tilladt.
        $this->assertTrue($envelope->fresh()->canRemind($r2->fresh()));
    }

    #[Test]
    public function late_error_or_viewed_webhooks_do_not_overwrite_a_signature(): void
    {
        config(['signing-room.idura.webhook_secret' => null]);
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com'], ['name' => 'B', 'email' => 'b@example.com']]);
        $a = $this->party($envelope, 'a@example.com');
        $this->sign($a);

        foreach (['SIGNATORY_SIGN_ERROR', 'SIGNATORY_SIGN_LINK_OPENED'] as $event) {
            $this->postJson(route('signing-room.webhook'), [
                'event' => $event,
                'signatureOrderId' => $envelope->idura_signature_order_id,
                'signatoryId' => $a->idura_signatory_id,
            ])->assertOk();
        }

        $this->assertEquals(SigningPartyStatus::Signed, $a->fresh()->status);
        $this->assertNotNull($a->fresh()->viewed_at);
    }

    #[Test]
    public function an_open_idura_order_block_is_visible_for_the_reminder_job(): void
    {
        Exceptions::fake();
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        $this->assertFalse($envelope->hasOpenBlock('idura_order_'));

        $this->idura->orderStatus = 'CANCELLED';
        $this->sync();

        $this->assertTrue($envelope->fresh()->hasOpenBlock('idura_order_'));
    }

    #[Test]
    public function an_order_block_is_resolved_when_idura_returns_the_order_again(): void
    {
        Exceptions::fake();
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);

        $this->idura->orderMissing = true;
        $this->sync();
        $this->assertTrue($envelope->fresh()->hasOpenBlock('idura_order_'));

        $this->idura->orderMissing = false;
        $this->sync();
        $this->assertFalse($envelope->fresh()->hasOpenBlock('idura_order_'));
    }

    #[Test]
    public function the_last_signature_on_a_locally_expired_envelope_still_completes_it(): void
    {
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        // Det daglige job satte den udløbet lokalt; Idura-ordren er stadig åben.
        SigningEnvelope::whereKey($envelope->id)->update(['status' => EnvelopeStatus::Expired->value]);

        $this->sign($this->party($envelope, 'a@example.com'));

        $this->assertEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
        Notification::assertSentTo($this->party($envelope, 'a@example.com'), EnvelopeCompletedNotification::class);
    }

    #[Test]
    public function a_draft_is_not_expired_by_the_daily_job(): void
    {
        $draft = SigningEnvelope::create([
            'title' => 'Kladde', 'status' => EnvelopeStatus::Draft,
            'original_document' => 'signing-room/original.pdf',
            'total_rounds' => 1, 'current_round' => 1, 'expires_at' => now()->subDay(),
        ]);

        $this->app->call([new \Fountainhead\SigningRoom\Jobs\ExpireSigningEnvelopes, 'handle']);

        $this->assertEquals(EnvelopeStatus::Draft, $draft->fresh()->status);
        $this->assertFalse($this->service->expireEnvelope($draft));
        $this->assertEquals(EnvelopeStatus::Draft, $draft->fresh()->status);
    }

    #[Test]
    public function a_late_rejection_does_not_overwrite_a_signature(): void
    {
        $creator = $this->creator();
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com'], ['name' => 'B', 'email' => 'b@example.com']], $creator->id);
        $a = $this->party($envelope, 'a@example.com');
        $stale = $a->fresh(); // indlæst før underskriften

        $this->sign($a);
        $this->service->handleRejected($stale, 'for sent');

        $this->assertEquals(SigningPartyStatus::Signed, $a->fresh()->status);
        Notification::assertNotSentTo($creator, \Fountainhead\SigningRoom\Notifications\PartyRejectedNotification::class);
    }

    #[Test]
    public function sending_an_old_draft_gives_it_a_fresh_expiry(): void
    {
        $draft = $this->service->createEnvelope(
            title: 'Gammel kladde',
            pdfPath: 'signing-room/original.pdf',
            parties: [['name' => 'A', 'email' => 'a@example.com']],
        );
        SigningEnvelope::whereKey($draft->id)->update(['expires_at' => now()->subDays(10)]);

        $sent = $this->service->sendEnvelope($draft->fresh());

        $this->assertTrue($sent->expires_at->isFuture());
        $this->assertTrue($this->party($sent, 'a@example.com')->isTokenValid());
    }

    #[Test]
    public function a_future_round_party_reminded_before_this_version_still_gets_the_round_mail(): void
    {
        $envelope = $this->sentEnvelope([
            ['name' => 'R1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'R2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ]);
        // Gammel påmindelse: notified_at sat, status uændret, intet party.notified.
        $this->party($envelope, 'r2@example.com')->update(['notified_at' => now()->subDay()]);

        $this->sign($this->party($envelope, 'r1@example.com'));

        Notification::assertSentToTimes($this->party($envelope, 'r2@example.com'), DocumentReadyNotification::class, 1);
    }

    #[Test]
    public function a_late_error_webhook_does_not_overwrite_a_rejection(): void
    {
        config(['signing-room.idura.webhook_secret' => null]);
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com'], ['name' => 'B', 'email' => 'b@example.com']]);
        $a = $this->party($envelope, 'a@example.com');
        $this->service->handleRejected($a, 'Nej');

        $this->postJson(route('signing-room.webhook'), [
            'event' => 'SIGNATORY_SIGN_ERROR',
            'signatureOrderId' => $envelope->idura_signature_order_id,
            'signatoryId' => $a->idura_signatory_id,
        ])->assertOk();

        $this->assertEquals(SigningPartyStatus::Rejected, $a->fresh()->status);
    }

    #[Test]
    public function a_role_with_whitespace_is_normalised_like_the_admin_form(): void
    {
        $envelope = $this->service->createEnvelope(
            title: 'Mellemrum',
            pdfPath: 'signing-room/original.pdf',
            parties: [['name' => 'A', 'email' => 'a@example.com', 'role' => ' Signer ']],
        );

        $this->assertSame(['signer'], $envelope->parties()->pluck('role')->all());
    }

    #[Test]
    public function a_signature_landing_during_round_notification_is_not_overwritten(): void
    {
        $envelope = $this->sentEnvelope([
            ['name' => 'R1', 'email' => 'r1@example.com', 'signing_round' => 1],
            ['name' => 'R2', 'email' => 'r2@example.com', 'signing_round' => 2],
        ]);
        $r2 = $this->party($envelope, 'r2@example.com');

        // Lige efter at notifyRoundParties har valgt R2, underskriver R2.
        $done = false;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$done, $r2) {
            if (! $done && str_contains($query->sql, 'from "signing_parties"') && str_contains($query->sql, 'not exists')) {
                $done = true;
                \Illuminate\Support\Facades\DB::table('signing_parties')->where('id', $r2->id)->update(['status' => 'signed', 'signed_at' => now()]);
            }
        });

        SigningEnvelope::whereKey($envelope->id)->update(['current_round' => 2]);
        $this->service->notifyRoundParties($envelope->fresh(), 2);

        $this->assertEquals(SigningPartyStatus::Signed, $r2->fresh()->status);
        Notification::assertNotSentTo($r2, DocumentReadyNotification::class);
    }

    #[Test]
    public function a_locally_expired_all_signed_envelope_is_completed_by_a_later_sync(): void
    {
        $envelope = $this->sentEnvelope([['name' => 'A', 'email' => 'a@example.com']]);
        SigningEnvelope::whereKey($envelope->id)->update(['status' => EnvelopeStatus::Expired->value]);
        $this->idura->failNextCloses = 1; // fuldførelsen i webhooken fejler

        $this->sign($this->party($envelope, 'a@example.com'));
        $this->assertEquals(EnvelopeStatus::Expired, $envelope->fresh()->status);

        $this->sync();

        $this->assertEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
    }

    #[Test]
    public function a_draft_with_less_than_a_day_left_also_gets_a_fresh_expiry(): void
    {
        $draft = $this->service->createEnvelope(
            title: 'Næsten udløbet',
            pdfPath: 'signing-room/original.pdf',
            parties: [['name' => 'A', 'email' => 'a@example.com']],
        );
        SigningEnvelope::whereKey($draft->id)->update(['expires_at' => now()->addHours(12)]);

        $sent = $this->service->sendEnvelope($draft->fresh());

        $this->assertTrue($sent->expires_at->gt(now()->addDays(2)));
    }

    /**
     * Kl. 10, og dagens daglige runde er allerede taget, så testen ikke
     * afhænger af uret.
     */
    private function outsideDailySlot(): void
    {
        $this->travelTo(now()->startOfDay()->setHour(10));
        \Illuminate\Support\Facades\Cache::put(SyncIduraSignatureStatus::DAILY_RETRY_CACHE_KEY.':'.now()->toDateString(), true, now()->addDay());
    }

    /** Næste dag kl. 04: dagens daglige runde er ikke taget endnu. */
    private function inDailySlot(): void
    {
        $this->travelTo(now()->startOfDay()->addDay()->setHour(4)->setMinute(0));
    }

    private function expiredAllSigned(string $email, \DateTimeInterface $signedAt): SigningEnvelope
    {
        $this->outsideDailySlot();
        $this->idura->orderStatus = 'OPEN';
        $envelope = $this->sentEnvelope([['name' => $email, 'email' => $email]]);
        $party = $this->party($envelope, $email);
        $this->idura->markSigned($party->idura_signatory_id);
        $party->update(['status' => SigningPartyStatus::Signed, 'signed_at' => $signedAt]);
        SigningEnvelope::whereKey($envelope->id)->update(['status' => EnvelopeStatus::Expired->value, 'expires_at' => now()->subMonths(2)]);

        return $envelope;
    }

    #[Test]
    public function expired_all_signed_envelopes_are_retried_by_the_age_of_the_last_signature(): void
    {
        $inside = $this->expiredAllSigned('inside@example.com', now()->subDays(13));
        $outside = $this->expiredAllSigned('outside@example.com', now()->subDays(15));

        $this->sync();

        $this->assertEquals(EnvelopeStatus::Completed, $inside->fresh()->status, 'Seneste underskrift for 13 dage siden: genforsøges.');
        $this->assertEquals(EnvelopeStatus::Expired, $outside->fresh()->status, 'Seneste underskrift for 15 dage siden og ingen blokering: historik, røres ikke.');
        $this->assertNull($outside->fresh()->last_synced_at, 'Den gamle kuvert må ikke engang slås op hos Idura.');
    }

    #[Test]
    public function an_old_expired_envelope_with_a_failed_attempt_keeps_being_retried(): void
    {
        $old = $this->expiredAllSigned('old@example.com', now()->subMonths(2));
        $old->logEvent(\Fountainhead\SigningRoom\Enums\SigningEventType::EnvelopeBlocked, metadata: ['reason' => 'completion_failed:RuntimeException:http_503']);

        $this->sync();

        $this->assertEquals(EnvelopeStatus::Completed, $old->fresh()->status);
    }

    #[Test]
    public function an_envelope_reported_as_lost_is_not_polled_every_hour(): void
    {
        $lost = $this->expiredAllSigned('lost@example.com', now()->subDays(2));
        $lost->logEvent(\Fountainhead\SigningRoom\Enums\SigningEventType::EnvelopeBlocked, metadata: ['reason' => 'idura_order_expired']);
        $calls = $this->idura->orderStatusCalls;

        $this->sync();

        $this->assertSame($calls, $this->idura->orderStatusCalls);
    }

    #[Test]
    public function an_old_envelope_with_only_a_resolved_sync_failure_is_not_reprocessed(): void
    {
        $old = $this->expiredAllSigned('old@example.com', now()->subMonths(2));
        $this->oldBlock($old, ['reason' => 'sync_failed:RuntimeException:http_503'], now()->subDays(20));
        $old->logEvent(\Fountainhead\SigningRoom\Enums\SigningEventType::EnvelopeUnblocked, metadata: ['reason_prefix' => 'sync_failed:'])
            ->forceFill(['created_at' => now()->subDays(19)])->save();
        $calls = $this->idura->orderStatusCalls;

        $this->sync();
        // Heller ikke i den daglige time: blokeringen er løst.
        $this->inDailySlot();
        $this->sync();

        $this->assertSame($calls, $this->idura->orderStatusCalls);
        $this->assertEquals(EnvelopeStatus::Expired, $old->fresh()->status);
    }

    #[Test]
    public function an_order_reported_as_cancelled_is_not_polled_every_hour_either(): void
    {
        $cancelled = $this->expiredAllSigned('cancelled@example.com', now()->subDays(2));
        $cancelled->logEvent(\Fountainhead\SigningRoom\Enums\SigningEventType::EnvelopeBlocked, metadata: ['reason' => 'idura_order_cancelled']);
        $calls = $this->idura->orderStatusCalls;

        $this->sync();

        $this->assertSame($calls, $this->idura->orderStatusCalls);
    }

    #[Test]
    public function an_old_envelope_whose_order_idura_did_not_know_keeps_being_polled(): void
    {
        // idura_order_missing kan være forbigående: ordren dukker op igen, og
        // så skal kuverten fuldføres, selv om underskriften er gammel. Selve
        // blokeringen holder kuverten med i EXPIRED_RETRY_DAYS (se
        // a_temporary_block_keeps_an_old_envelope_retried_...).
        $old = $this->expiredAllSigned('missing@example.com', now()->subMonths(2));
        $old->logEvent(\Fountainhead\SigningRoom\Enums\SigningEventType::EnvelopeBlocked, metadata: ['reason' => 'idura_order_missing']);

        $this->sync();

        $this->assertEquals(EnvelopeStatus::Completed, $old->fresh()->status);
    }

    #[Test]
    public function an_old_envelope_blocked_by_a_legacy_viewer_is_completed_once_the_viewer_is_removed(): void
    {
        // Operatøren har fjernet den gamle kopimodtager hos Idura (her: ingen
        // åben signatory tilbage), men blokeringen står stadig åben her.
        $old = $this->expiredAllSigned('legacy@example.com', now()->subMonths(2));
        $old->logEvent(\Fountainhead\SigningRoom\Enums\SigningEventType::EnvelopeBlocked, metadata: ['reason' => 'legacy_viewer_signatory:sig-x']);

        $this->sync();

        $this->assertEquals(EnvelopeStatus::Completed, $old->fresh()->status);
    }

    private function oldBlock(SigningEnvelope $envelope, array $metadata, \DateTimeInterface $at): void
    {
        $event = $envelope->logEvent(\Fountainhead\SigningRoom\Enums\SigningEventType::EnvelopeBlocked, metadata: $metadata);
        $event->forceFill(['created_at' => $at])->save();
    }

    #[Test]
    public function a_temporary_block_keeps_an_old_envelope_retried_for_fourteen_days_after_it_was_reported(): void
    {
        $recent = $this->expiredAllSigned('recent@example.com', now()->subMonths(2));
        $this->oldBlock($recent, ['reason' => 'idura_order_missing'], now()->subDays(13));
        $stale = $this->expiredAllSigned('stale@example.com', now()->subMonths(2));
        $this->oldBlock($stale, ['reason' => 'completion_failed:RuntimeException:http_503'], now()->subDays(15));

        $this->sync();

        $this->assertEquals(EnvelopeStatus::Completed, $recent->fresh()->status, 'Rapporteret for 13 dage siden: genforsøges.');
        $this->assertEquals(EnvelopeStatus::Expired, $stale->fresh()->status, 'Rapporteret for 15 dage siden: står åben til manuel handling.');
        $this->assertNull($stale->fresh()->last_synced_at);
    }

    #[Test]
    public function an_old_legacy_viewer_block_is_retried_once_a_day_however_old_it_is(): void
    {
        $old = $this->expiredAllSigned('legacy-old@example.com', now()->subMonths(3));
        $this->oldBlock($old, ['reason' => 'legacy_viewer_signatory:sig-x'], now()->subMonths(2));
        $calls = $this->idura->orderStatusCalls;

        $this->sync();
        $this->assertSame($calls, $this->idura->orderStatusCalls, 'Uden for den daglige time: ikke hver time.');

        $this->inDailySlot();
        $this->sync();

        $this->assertEquals(EnvelopeStatus::Completed, $old->fresh()->status);
    }

    #[Test]
    public function a_cleared_block_restarts_the_window_so_an_unfinished_envelope_is_not_dropped(): void
    {
        // Kørsel 1 ophæver idura_order_missing, men en anden proces holder
        // fuldførelsen: ikke fuldført og ingen ny blokering. Kørsel 2 skal
        // stadig tage den, selv om underskriften er gammel.
        $old = $this->expiredAllSigned('cleared@example.com', now()->subMonths(2));
        $this->oldBlock($old, ['reason' => 'idura_order_missing'], now()->subDays(13));
        SigningEnvelope::whereKey($old->id)->update(['completion_started_at' => now()]);

        $this->sync();
        $this->assertEquals(EnvelopeStatus::Expired, $old->fresh()->status, 'Forudsætning: kørsel 1 fuldfører ikke.');
        $this->assertFalse($old->fresh()->hasOpenBlock('idura_order_missing'), 'Forudsætning: blokeringen er ophævet.');

        // To dage senere: blokeringen er 15 dage gammel, kun ophævelsen er
        // inden for vinduet.
        SigningEnvelope::whereKey($old->id)->update(['completion_started_at' => null]);
        $this->travel(2)->days();
        $this->sync();
        $this->travelBack();

        $this->assertEquals(EnvelopeStatus::Completed, $old->fresh()->status);
    }

    #[Test]
    public function an_unexpected_order_status_is_not_final_for_an_expired_envelope(): void
    {
        $envelope = $this->expiredAllSigned('pending@example.com', now()->subDays(2));
        $envelope->logEvent(\Fountainhead\SigningRoom\Enums\SigningEventType::EnvelopeBlocked, metadata: ['reason' => 'idura_order_pending']);

        $this->sync();

        $this->assertEquals(EnvelopeStatus::Completed, $envelope->fresh()->status, 'Idura returnerer OPEN igen: kuverten fuldføres.');
    }

    #[Test]
    public function an_old_resolved_legacy_viewer_block_does_not_keep_an_envelope_retried(): void
    {
        // Kopimodtagerblokeringen er ophævet for længe siden: kuverten er
        // historik, selv om prefiltret ser den gamle blokering.
        $old = $this->expiredAllSigned('legacy-resolved@example.com', now()->subMonths(3));
        $this->oldBlock($old, ['reason' => 'legacy_viewer_signatory:sig-x'], now()->subMonths(2));
        $old->logEvent(\Fountainhead\SigningRoom\Enums\SigningEventType::EnvelopeUnblocked, metadata: ['reason_prefix' => 'legacy_viewer_signatory'])
            ->forceFill(['created_at' => now()->subMonths(2)->addDay()])->save();
        $calls = $this->idura->orderStatusCalls;

        $this->sync();
        // Heller ikke i den daglige runde: blokeringen er løst, og kuverten
        // har ingen kopimodtager med Idura-id.
        $this->inDailySlot();
        $this->sync();

        $this->assertSame($calls, $this->idura->orderStatusCalls);
    }

    #[Test]
    public function an_old_envelope_that_expired_before_the_legacy_report_existed_is_reported_and_then_completed(): void
    {
        // Udløbet lokalt før #20: ingen blokering, ingen aktivitet i vinduet,
        // men en kopimodtager står stadig som SIGNER hos Idura.
        Exceptions::fake();
        $this->idura->orderStatus = 'OPEN';
        $envelope = $this->sentEnvelope([
            ['name' => 'Underskriver', 'email' => 'old-signer@example.com'],
            ['name' => 'Kopi', 'email' => 'old-viewer@example.com', 'role' => SigningPartyRole::Viewer->value],
        ]);
        $legacyId = $this->addLegacyViewer($envelope, 'old-viewer@example.com');
        $signer = $this->party($envelope, 'old-signer@example.com');
        $this->idura->markSigned($signer->idura_signatory_id);
        $signer->update(['status' => SigningPartyStatus::Signed, 'signed_at' => now()->subMonths(2)]);
        SigningEnvelope::whereKey($envelope->id)->update(['status' => EnvelopeStatus::Expired->value, 'expires_at' => now()->subMonths(1)]);

        $this->outsideDailySlot();
        $this->sync();
        Exceptions::assertNothingReported();

        $this->inDailySlot();
        $this->sync();
        $this->travel(1)->day();
        $this->sync();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (\Fountainhead\SigningRoom\Exceptions\EnvelopeBlockedByViewerException $e) => str_contains($e->getMessage(), "slet viewer-signatory {$legacyId} hos Idura"));
        $this->assertEquals(EnvelopeStatus::Expired, $envelope->fresh()->status);

        // Operatøren retter det måneder senere.
        $this->travel(60)->days();
        unset($this->idura->signatories[$legacyId]);
        $this->party($envelope, 'old-viewer@example.com')->update(['idura_signatory_id' => null]);
        $this->sync();
        $this->travelBack();

        $this->assertEquals(EnvelopeStatus::Completed, $envelope->fresh()->status);
    }

    #[Test]
    public function a_modern_viewer_does_not_make_an_old_envelope_retryable(): void
    {
        $this->idura->orderStatus = 'OPEN';
        $envelope = $this->sentEnvelope([
            ['name' => 'Underskriver', 'email' => 'modern-signer@example.com'],
            ['name' => 'Kopi', 'email' => 'modern-viewer@example.com', 'role' => SigningPartyRole::Viewer->value],
        ]);
        $this->assertNull($this->party($envelope, 'modern-viewer@example.com')->idura_signatory_id, 'Forudsætning: ny kopimodtager uden Idura-id.');
        $this->inDailySlot();
        $signer = $this->party($envelope, 'modern-signer@example.com');
        $signer->update(['status' => SigningPartyStatus::Signed, 'signed_at' => now()->subMonths(2)]);
        SigningEnvelope::whereKey($envelope->id)->update(['status' => EnvelopeStatus::Expired->value, 'expires_at' => now()->subMonths(1)]);
        $calls = $this->idura->orderStatusCalls;

        $this->sync();

        $this->assertSame($calls, $this->idura->orderStatusCalls);
    }

    #[Test]
    public function a_failure_fixed_after_the_window_is_picked_up_by_the_daily_retry(): void
    {
        // Fx en disk, der fejlede på dag 0 og rettes på dag 16.
        $old = $this->expiredAllSigned('disk@example.com', now()->subMonths(2));
        $this->oldBlock($old, ['reason' => 'completion_failed:RuntimeException:abc'], now()->subDays(16));
        $calls = $this->idura->orderStatusCalls;

        $this->sync();
        $this->assertSame($calls, $this->idura->orderStatusCalls, 'Uden for vinduet: ikke hver time.');

        $this->inDailySlot();
        $this->sync();

        $this->assertEquals(EnvelopeStatus::Completed, $old->fresh()->status);
    }

    #[Test]
    public function a_final_order_block_is_checked_daily_and_cleared_if_idura_returns_the_order(): void
    {
        $lost = $this->expiredAllSigned('lost-daily@example.com', now()->subMonths(2));
        $this->oldBlock($lost, ['reason' => 'idura_order_cancelled'], now()->subMonths(1));
        $calls = $this->idura->orderStatusCalls;

        $this->sync();
        $this->assertSame($calls, $this->idura->orderStatusCalls, 'Ikke hver time.');

        // Idura svarer OPEN igen (fx en fejlaflæsning): den daglige runde
        // løser blokeringen og fuldfører.
        $this->inDailySlot();
        $this->idura->orderStatus = 'OPEN';
        $this->sync();

        $this->assertEquals(EnvelopeStatus::Completed, $lost->fresh()->status);
    }

    #[Test]
    public function a_legacy_viewer_outside_the_current_round_does_not_keep_an_old_envelope_polled(): void
    {
        // Afvist kopimodtager i runde 1, kuverten står i runde 2: rapporten
        // ser kun den aktuelle runde, så udvælgelsen gør det samme.
        $this->idura->orderStatus = 'OPEN';
        $envelope = $this->sentEnvelope([
            ['name' => 'Underskriver', 'email' => 'round-signer@example.com'],
            ['name' => 'Kopi', 'email' => 'round-viewer@example.com', 'role' => SigningPartyRole::Viewer->value],
        ]);
        $this->addLegacyViewer($envelope, 'round-viewer@example.com');
        $this->party($envelope, 'round-viewer@example.com')->update(['status' => SigningPartyStatus::Rejected]);
        $this->party($envelope, 'round-signer@example.com')->update(['status' => SigningPartyStatus::Signed, 'signed_at' => now()->subMonths(2)]);
        SigningEnvelope::whereKey($envelope->id)->update(['status' => EnvelopeStatus::Expired->value, 'expires_at' => now()->subMonths(1), 'current_round' => 2]);
        $this->inDailySlot();
        $calls = $this->idura->orderStatusCalls;

        $this->sync();

        $this->assertSame($calls, $this->idura->orderStatusCalls);
    }

    #[Test]
    public function the_daily_retry_is_taken_by_the_first_run_from_four_even_if_the_four_oclock_run_was_lost(): void
    {
        $old = $this->expiredAllSigned('daily@example.com', now()->subMonths(2));
        $this->oldBlock($old, ['reason' => 'completion_failed:RuntimeException:abc'], now()->subDays(16));
        $calls = $this->idura->orderStatusCalls;

        // Næste dag kl. 02: før den daglige time.
        $this->travelTo(now()->startOfDay()->addDay()->setHour(2));
        $this->sync();
        $this->assertSame($calls, $this->idura->orderStatusCalls, 'Før kl. 04: ingen daglig runde.');

        // Kørslen kl. 04 blev tabt; kl. 05 tager den daglige runde.
        $this->travelTo(now()->setHour(5));
        $this->idura->orderStatus = 'OPEN';
        $this->sync();
        $this->assertEquals(EnvelopeStatus::Completed, $old->fresh()->status);
    }

    #[Test]
    public function the_daily_retry_runs_only_once_a_day(): void
    {
        // Gammel kopimodtager, der stadig står hos Idura, og en gammel,
        // allerede rapporteret blokering: den daglige runde ændrer intet.
        $this->outsideDailySlot();
        $this->idura->orderStatus = 'OPEN';
        $envelope = $this->sentEnvelope([
            ['name' => 'Underskriver', 'email' => 'once-signer@example.com'],
            ['name' => 'Kopi', 'email' => 'once-viewer@example.com', 'role' => SigningPartyRole::Viewer->value],
        ]);
        $legacyId = $this->addLegacyViewer($envelope, 'once-viewer@example.com');
        $signer = $this->party($envelope, 'once-signer@example.com');
        $this->idura->markSigned($signer->idura_signatory_id);
        $signer->update(['status' => SigningPartyStatus::Signed, 'signed_at' => now()->subMonths(2)]);
        SigningEnvelope::whereKey($envelope->id)->update(['status' => EnvelopeStatus::Expired->value, 'expires_at' => now()->subMonths(1)]);
        $this->oldBlock($envelope, ['reason' => 'legacy_viewer_signatory:'.$legacyId], now()->subMonths(1));
        $this->inDailySlot();
        $calls = $this->idura->orderStatusCalls;

        $this->sync();
        $this->assertSame($calls + 1, $this->idura->orderStatusCalls, 'Forudsætning: den daglige runde slår kuverten op.');
        $this->travel(1)->hour();
        $this->sync();

        $this->assertSame($calls + 1, $this->idura->orderStatusCalls, 'Anden kørsel samme dag: ingen ny daglig runde.');
        $this->assertSame(1, $envelope->events()->where('event_type', 'envelope.blocked')->count(), 'Ingen ny rapport.');
    }

    #[Test]
    public function a_run_that_fails_as_a_whole_gives_the_daily_retry_back(): void
    {
        $this->inDailySlot();
        $key = SyncIduraSignatureStatus::DAILY_RETRY_CACHE_KEY.':'.now()->toDateString();
        // Forespørgslen fejler samlet (her: events-tabellen mangler).
        \Illuminate\Support\Facades\Schema::drop('signing_events');

        try {
            $this->sync();
            $this->fail('Forventede, at kørslen fejlede.');
        } catch (\Illuminate\Database\QueryException) {
            // forventet
        }

        $this->assertFalse(\Illuminate\Support\Facades\Cache::has($key), 'Næste kørsel skal kunne tage dagens runde.');
    }

    #[Test]
    public function a_short_idura_outage_in_the_daily_retry_does_not_bring_old_envelopes_back_to_hourly_polling(): void
    {
        $old = $this->expiredAllSigned('outage@example.com', now()->subMonths(3));
        $this->oldBlock($old, ['reason' => 'completion_failed:RuntimeException:abc'], now()->subMonths(2));
        // Den daglige runde ramte et 503 og ophævede det næste kørsel.
        $this->oldBlock($old, ['reason' => 'sync_failed:RuntimeException:http_503'], now()->subHours(2));
        $old->logEvent(\Fountainhead\SigningRoom\Enums\SigningEventType::EnvelopeUnblocked, metadata: ['reason_prefix' => 'sync_failed:']);
        $calls = $this->idura->orderStatusCalls;

        $this->sync();

        $this->assertSame($calls, $this->idura->orderStatusCalls, 'Egne sync-fejl er ikke aktivitet: ikke hver time.');
    }

    private function remind(): void
    {
        (new \Fountainhead\SigningRoom\Jobs\SendSigningReminders)->handle();
    }

    #[Test]
    public function a_freshly_sent_envelope_is_not_reminded_the_same_day(): void
    {
        // Sendt kl. 08:55, påmindelsesjobbet kører kl. 09:00: last_reminder_at
        // er tom, men parten fik sin mail for fem minutter siden.
        $envelope = $this->sentEnvelope([['name' => 'Ny', 'email' => 'fresh@example.com']]);
        SigningEnvelope::whereKey($envelope->id)->update(['reminder_interval' => 7, 'last_reminder_at' => null]);

        $this->remind();

        Notification::assertNotSentTo($this->party($envelope, 'fresh@example.com'), \Fountainhead\SigningRoom\Notifications\SigningReminderNotification::class);
    }

    #[Test]
    public function a_signer_is_reminded_once_the_interval_since_the_last_mail_has_passed(): void
    {
        $envelope = $this->sentEnvelope([
            ['name' => 'Gammel', 'email' => 'due@example.com'],
            ['name' => 'Manuelt mindet', 'email' => 'manual@example.com'],
        ]);
        SigningEnvelope::whereKey($envelope->id)->update(['reminder_interval' => 7, 'last_reminder_at' => now()->subDays(30)]);
        $this->party($envelope, 'due@example.com')->update(['notified_at' => now()->subDays(8)]);
        // Manuel påmindelse for en time siden: kun notified_at er sat.
        $this->party($envelope, 'manual@example.com')->update(['notified_at' => now()->subHour()]);

        $this->remind();

        Notification::assertSentToTimes($this->party($envelope, 'due@example.com'), \Fountainhead\SigningRoom\Notifications\SigningReminderNotification::class, 1);
        Notification::assertNotSentTo($this->party($envelope, 'manual@example.com'), \Fountainhead\SigningRoom\Notifications\SigningReminderNotification::class);
    }

    #[Test]
    public function reminding_one_signer_does_not_hold_back_another_who_was_skipped(): void
    {
        $this->travelTo(now()->startOfDay()->setHour(9));
        $envelope = $this->sentEnvelope([
            ['name' => 'A', 'email' => 'a@example.com'],
            ['name' => 'B', 'email' => 'b@example.com'],
        ]);
        SigningEnvelope::whereKey($envelope->id)->update(['reminder_interval' => 7, 'last_reminder_at' => now()->subDays(30)]);
        $this->party($envelope, 'a@example.com')->update(['notified_at' => now()->subDays(8)]);
        // B fik en manuel påmindelse for fire dage siden.
        $this->party($envelope, 'b@example.com')->update(['notified_at' => now()->subDays(4)]);

        $this->remind();
        Notification::assertSentToTimes($this->party($envelope, 'a@example.com'), \Fountainhead\SigningRoom\Notifications\SigningReminderNotification::class, 1);
        Notification::assertNotSentTo($this->party($envelope, 'b@example.com'), \Fountainhead\SigningRoom\Notifications\SigningReminderNotification::class);

        // Tre dage senere er B forfalden, selv om A blev mindet.
        $this->travel(3)->days();
        $this->remind();

        Notification::assertSentToTimes($this->party($envelope, 'b@example.com'), \Fountainhead\SigningRoom\Notifications\SigningReminderNotification::class, 1);
        Notification::assertSentToTimes($this->party($envelope, 'a@example.com'), \Fountainhead\SigningRoom\Notifications\SigningReminderNotification::class, 1);
    }

    #[Test]
    public function a_reminder_written_seconds_into_the_last_run_is_due_exactly_one_interval_later(): void
    {
        // Sidste mail kl. 09:00:03; jobbet kører igen kl. 09:00:00 efter
        // præcis intervallet. Interval 1 = hver dag, ikke hver anden.
        $this->travelTo(now()->startOfDay()->setTime(9, 0, 3));
        $envelope = $this->sentEnvelope([['name' => 'Dagligt', 'email' => 'daily-r@example.com']]);
        SigningEnvelope::whereKey($envelope->id)->update(['reminder_interval' => 1, 'last_reminder_at' => now()]);
        $this->party($envelope, 'daily-r@example.com')->update(['notified_at' => now()]);

        $this->travelTo(now()->addDay()->setTime(9, 0, 0));
        $this->remind();

        Notification::assertSentToTimes($this->party($envelope, 'daily-r@example.com'), \Fountainhead\SigningRoom\Notifications\SigningReminderNotification::class, 1);
    }

    #[Test]
    public function a_run_that_reminds_nobody_does_not_push_the_envelope_interval_back(): void
    {
        $envelope = $this->sentEnvelope([['name' => 'Sprunget over', 'email' => 'skipped@example.com']]);
        SigningEnvelope::whereKey($envelope->id)->update(['reminder_interval' => 7, 'last_reminder_at' => now()->subDays(30)]);
        // Manuel påmindelse for fire dage siden: springes over i dag.
        $this->party($envelope, 'skipped@example.com')->update(['notified_at' => now()->subDays(4)]);

        $this->remind();
        Notification::assertNotSentTo($this->party($envelope, 'skipped@example.com'), \Fountainhead\SigningRoom\Notifications\SigningReminderNotification::class);
        $this->assertTrue($envelope->fresh()->last_reminder_at->lte(now()->subDays(29)), 'Kuvertens interval er uændret.');

        // Tre dage senere er parten forfalden og mindes.
        $this->travel(3)->days();
        $this->remind();
        $this->travelBack();

        Notification::assertSentToTimes($this->party($envelope, 'skipped@example.com'), \Fountainhead\SigningRoom\Notifications\SigningReminderNotification::class, 1);
    }

    #[Test]
    public function a_daily_run_where_every_envelope_failed_is_taken_over_by_the_next_run(): void
    {
        $old = $this->expiredAllSigned('down@example.com', now()->subMonths(2));
        $this->oldBlock($old, ['reason' => 'completion_failed:RuntimeException:abc'], now()->subDays(20));
        $this->inDailySlot();
        $this->idura->orderStatusFailure = new \RuntimeException('Idura request failed with status 503');

        $this->sync();
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has(SyncIduraSignatureStatus::DAILY_RETRY_CACHE_KEY.':'.now()->toDateString()));

        $this->idura->orderStatusFailure = null;
        $this->travel(1)->hour();
        $this->sync();

        $this->assertEquals(EnvelopeStatus::Completed, $old->fresh()->status);
    }
}
