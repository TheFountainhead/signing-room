<?php

namespace Fountainhead\SigningRoom\Tests\Unit;

use Fountainhead\SigningRoom\Services\IduraSignatureService;
use Fountainhead\SigningRoom\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class IduraSignatureServiceTest extends TestCase
{
    private const ENDPOINT = 'https://test-idura.test/graphql';

    private const SIGNATORY_ID = 'sig-123';

    private const OTHER_SIGNATORY_ID = 'sig-456';

    protected function setUp(): void
    {
        parent::setUp();

        // IduraSignatureService needs real credentials to issue queries at all
        config([
            'signing-room.idura.endpoint'       => self::ENDPOINT,
            'signing-room.idura.client_id'      => 'test-id',
            'signing-room.idura.client_secret'  => 'test-secret',
        ]);
    }

    private function service(): IduraSignatureService
    {
        return new IduraSignatureService;
    }

    /** Build a GraphQL response with a given signatures list on a single document. */
    private function fakeSignatureResponse(array $signatures): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'data' => [
                    'signatory' => [
                        'id'             => self::SIGNATORY_ID,
                        'signatureOrder' => [
                            'documents' => [
                                [
                                    'signatures' => $signatures,
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);
    }

    /** @test */
    public function returns_cpr_from_direct_jwt_signature(): void
    {
        $this->fakeSignatureResponse([
            [
                '__typename' => 'JWTSignature',
                'signatory'  => ['id' => self::SIGNATORY_ID],
                'claims'     => [
                    ['name' => 'sub', 'value' => 'abc'],
                    ['name' => 'cprNumberIdentifier', 'value' => '1234567890'],
                ],
            ],
        ]);

        $this->assertSame('1234567890', $this->service()->getSignatoryEvidence(self::SIGNATORY_ID));
    }

    /** @test */
    public function returns_cpr_from_nested_composite_signature(): void
    {
        $this->fakeSignatureResponse([
            [
                '__typename' => 'CompositeSignature',
                'signatory'  => ['id' => self::SIGNATORY_ID],
                'signatures' => [
                    [
                        '__typename' => 'DrawableSignature',
                    ],
                    [
                        '__typename' => 'JWTSignature',
                        'claims'     => [
                            ['name' => 'cprNumberIdentifier', 'value' => '9876543210'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('9876543210', $this->service()->getSignatoryEvidence(self::SIGNATORY_ID));
    }

    /** @test */
    public function returns_null_when_only_drawable_signature_present(): void
    {
        $this->fakeSignatureResponse([
            [
                '__typename' => 'DrawableSignature',
                'signatory'  => ['id' => self::SIGNATORY_ID],
            ],
        ]);

        $this->assertNull($this->service()->getSignatoryEvidence(self::SIGNATORY_ID));
    }

    /** @test */
    public function ignores_signatures_belonging_to_other_signatories(): void
    {
        // The requested signatory has no JWT signature; a *different* signatory
        // does. We must not leak the other signatory's CPR.
        $this->fakeSignatureResponse([
            [
                '__typename' => 'JWTSignature',
                'signatory'  => ['id' => self::OTHER_SIGNATORY_ID],
                'claims'     => [
                    ['name' => 'cprNumberIdentifier', 'value' => 'other-cpr'],
                ],
            ],
            [
                '__typename' => 'DrawableSignature',
                'signatory'  => ['id' => self::SIGNATORY_ID],
            ],
        ]);

        $this->assertNull($this->service()->getSignatoryEvidence(self::SIGNATORY_ID));
    }

    /** @test */
    public function returns_null_when_no_signatures_exist(): void
    {
        $this->fakeSignatureResponse([]);

        $this->assertNull($this->service()->getSignatoryEvidence(self::SIGNATORY_ID));
    }

    /** @test */
    public function returns_null_and_swallows_graphql_errors(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'errors' => [
                    ['message' => 'Cannot query field "foo" on type "Signatory".'],
                ],
            ]),
        ]);

        $this->assertNull($this->service()->getSignatoryEvidence(self::SIGNATORY_ID));
    }

    /** @test */
    public function returns_null_when_signatory_not_found(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'data' => ['signatory' => null],
            ]),
        ]);

        $this->assertNull($this->service()->getSignatoryEvidence(self::SIGNATORY_ID));
    }
}
