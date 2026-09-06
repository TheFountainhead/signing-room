<?php

namespace Fountainhead\SigningRoom\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Fountainhead\SigningRoom\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SigningPartyTest extends TestCase
{
    use RefreshDatabase;

    private function createEnvelopeAndParty(): SigningParty
    {
        $envelope = SigningEnvelope::create([
            'title'             => 'Unit Test Aftale',
            'status'            => EnvelopeStatus::Sent,
            'original_document' => 'signing-room/unit-test.pdf',
            'total_rounds'      => 1,
            'current_round'     => 1,
        ]);

        return SigningParty::create([
            'signing_envelope_id' => $envelope->id,
            'name'                => 'CPR Testperson',
            'email'               => 'unit@example.com',
            'status'              => SigningPartyStatus::Pending,
            'signing_round'       => 1,
        ]);
    }

    // -------------------------------------------------------------------------
    // CPR mutator
    // -------------------------------------------------------------------------

    #[Test]
    public function cpr_mutator_sets_both_encrypted_and_hash(): void
    {
        $party = $this->createEnvelopeAndParty();

        $party->cpr = '0101901234';
        $party->save();

        $fresh = $party->fresh();

        // cpr_encrypted must be a non-empty encrypted blob (not the raw CPR)
        $this->assertNotEmpty($fresh->cpr_encrypted);
        $this->assertNotEquals('0101901234', $fresh->cpr_encrypted);

        // cpr_hash must be a sha256 of the raw CPR
        $expectedHash = hash('sha256', '0101901234');
        $this->assertEquals($expectedHash, $fresh->cpr_hash);
    }

    // -------------------------------------------------------------------------
    // signingUrl()
    // -------------------------------------------------------------------------

    #[Test]
    public function signing_url_contains_token_query_parameter(): void
    {
        $party = $this->createEnvelopeAndParty();

        $url = $party->signingUrl();

        $this->assertStringContainsString('?token=', $url);
        $this->assertStringContainsString($party->signing_token, $url);
        $this->assertStringContainsString($party->uuid, $url);
    }
}
