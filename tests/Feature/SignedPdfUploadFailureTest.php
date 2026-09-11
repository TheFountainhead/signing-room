<?php

namespace Fountainhead\SigningRoom\Tests\Feature;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Fountainhead\SigningRoom\Services\IduraSignatureService;
use Fountainhead\SigningRoom\Services\SigningRoomService;
use Fountainhead\SigningRoom\Tests\TestCase;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * Målt 11. sep 2026 på sign.frankston.io: 14 kuverter, 9 med signeret PDF, og
 * alle 9 filer fandtes i bucketen. Kæden holdt — men den holdt tilfældigt.
 *
 * `completeEnvelope()` kaldte `$disk->put()` uden at se på returværdien, og
 * begge s3-diske i konsumerende apps er konfigureret med 'throw' => false.
 * En fejlet upload returnerer derfor `false` i stedet for at kaste, hvorefter
 * koden gemte stien i `signed_document` og markerede kuverten Completed.
 *
 * Konsekvensen er ikke en fejlbesked, men et tavst tab: Idura sletter sin egen
 * kopi efter `retain_documents` dage (7 som standard), så vores fil er den
 * eneste tilbage. Databasen ville påstå at dokumentet fandtes, og ingen ville
 * opdage noget før nogen bad om at se det.
 *
 * Testene låser begge retninger, så rettelsen ikke kan falde tilbage:
 *   - fejlet upload → exception, ingen sti gemt, kuverten ikke Completed
 *   - vellykket upload → sti gemt og kuverten fuldført som hidtil
 */
class SignedPdfUploadFailureTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER_ID = 'SignatureOrder:tenant|order-abc';

    private function createEnvelope(array $attributes = []): SigningEnvelope
    {
        return SigningEnvelope::create(array_merge([
            'title'                    => 'Test Aftale',
            'status'                   => EnvelopeStatus::Sent,
            'original_document'        => 'signing-room/test.pdf',
            'idura_signature_order_id' => self::ORDER_ID,
            'total_rounds'             => 1,
            'current_round'            => 1,
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

    /**
     * Idura returnerer den færdige PDF som base64 i closeOrder-svaret.
     */
    private function fakeIduraReturningSignedPdf(): IduraSignatureService
    {
        $idura = $this->createMock(IduraSignatureService::class);

        $idura->method('closeOrder')->willReturn([
            'documents' => [
                ['blob' => base64_encode('%PDF-1.4 signeret indhold')],
            ],
        ]);

        return $idura;
    }

    #[Test]
    public function a_failed_upload_aborts_instead_of_recording_a_path_to_a_missing_file(): void
    {
        $envelope = $this->createEnvelope();
        $party = $this->createParty($envelope);

        // Spejler produktion: 'throw' => false, så put() returnerer false i
        // stedet for at kaste når skrivningen mislykkes.
        $disk = $this->createMock(Filesystem::class);
        $disk->method('put')->willReturn(false);
        Storage::shouldReceive('disk')->andReturn($disk);

        $service = new SigningRoomService($this->fakeIduraReturningSignedPdf());

        $this->expectException(\RuntimeException::class);

        try {
            $service->handleSigned($party);
        } finally {
            $envelope->refresh();

            $this->assertNull(
                $envelope->signed_document,
                'En sti må aldrig gemmes når filen ikke kom frem.'
            );
            $this->assertNotEquals(
                EnvelopeStatus::Completed,
                $envelope->status,
                'Kuverten må ikke markeres fuldført uden en gemt PDF.'
            );
        }
    }

    #[Test]
    public function a_successful_upload_still_records_the_path_and_completes_the_envelope(): void
    {
        $envelope = $this->createEnvelope();
        $party = $this->createParty($envelope);

        $disk = $this->createMock(Filesystem::class);
        $disk->method('put')->willReturn(true);
        Storage::shouldReceive('disk')->andReturn($disk);

        $service = new SigningRoomService($this->fakeIduraReturningSignedPdf());

        $service->handleSigned($party);

        $envelope->refresh();

        $this->assertSame(
            'signing-room/' . $envelope->uuid . '/signed.pdf',
            $envelope->signed_document
        );
        $this->assertEquals(EnvelopeStatus::Completed, $envelope->status);
        $this->assertNotNull($envelope->completed_at);
    }
}
