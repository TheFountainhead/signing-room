<?php

namespace Fountainhead\SigningRoom\Tests\Feature;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

/**
 * FHT: The Idura/Criipto webhook endpoint mutates signing state (marks parties
 * signed/rejected, expires envelopes) but its HMAC path was effectively dead:
 *
 *   1. The order-creation mutation never registered a `secret`, so Criipto
 *      never sent an `X-Criipto-Signature` header at all.
 *   2. Even if it had, validation keyed the HMAC on the raw base64 *string*
 *      rather than the decoded Blob bytes Criipto signs with, so every
 *      signature would have mismatched.
 *
 * Per the Criipto schema (CreateSignatureOrderWebhookInput):
 *   "If defined, webhook invocations will have a X-Criipto-Signature header
 *    containing a HMAC-SHA256 signature (as a base64 string) of the webhook
 *    request body (utf-8). The secret should be between 256 and 512 bits."
 * — `secret` is a Blob (base64-encoded bytes), so the HMAC key is the *decoded*
 * secret.
 *
 * These tests lock the two-phase rollout:
 *   - Phase 1 (IDURA_WEBHOOK_ENFORCE=false): invalid/missing signatures are
 *     logged (Flare) but still processed, so in-flight orders created before
 *     the secret was registered keep flowing.
 *   - Phase 2 (true): invalid/missing signatures are rejected with 403 and
 *     produce no state change.
 *
 * Plus the fail-closed guard: a missing secret aborts(500) in production
 * (loud misconfiguration) but is tolerated outside production.
 */
class IduraWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'c2VjcmV0LWtleS1iZXR3ZWVuLTI1Ni1hbmQtNTEyLWJpdHMtbG9uZ2cu'; // base64 Blob

    private const ORDER_ID = 'SignatureOrder:tenant|order-abc';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('signing-room.idura.webhook_secret', self::SECRET);
        $app['config']->set('signing-room.idura.webhook_enforce', false);
    }

    private function createSentEnvelope(): SigningEnvelope
    {
        return SigningEnvelope::create([
            'title'             => 'Test Aftale',
            'status'            => EnvelopeStatus::Sent,
            'original_document' => 'signing-room/test.pdf',
            'idura_signature_order_id' => self::ORDER_ID,
            'total_rounds'      => 1,
            'current_round'     => 1,
        ]);
    }

    /**
     * The webhook payload that, when processed, mutates state: an
     * SIGNATURE_ORDER_EXPIRED event flips a Sent envelope to Expired. We use
     * this as the observable side effect to prove processing happened (or was
     * blocked).
     */
    private function payload(): array
    {
        return [
            'event'            => 'SIGNATURE_ORDER_EXPIRED',
            'signatureOrderId' => self::ORDER_ID,
        ];
    }

    private function sign(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, base64_decode(self::SECRET), true));
    }

    private function postWebhook(array $payload, ?string $signature): \Illuminate\Testing\TestResponse
    {
        $headers = ['Accept' => 'application/json'];
        if ($signature !== null) {
            $headers['X-Criipto-Signature'] = $signature;
        }

        return $this->postJson(route('signing-room.webhook'), $payload, $headers);
    }

    /** @test */
    public function valid_signature_is_accepted_and_processed(): void
    {
        $envelope = $this->createSentEnvelope();
        $payload  = $this->payload();
        $body     = json_encode($payload);

        $this->postWebhook($payload, $this->sign($body))
            ->assertOk();

        $this->assertSame(
            EnvelopeStatus::Expired,
            $envelope->fresh()->status,
            'A correctly-signed webhook must be processed (envelope expired).'
        );
    }

    /** @test */
    public function wrong_signature_with_enforcement_is_rejected_403_without_state_change(): void
    {
        config(['signing-room.idura.webhook_enforce' => true]);

        $envelope = $this->createSentEnvelope();
        $payload  = $this->payload();

        $this->postWebhook($payload, 'this-is-not-a-valid-signature')
            ->assertStatus(403);

        $this->assertSame(
            EnvelopeStatus::Sent,
            $envelope->fresh()->status,
            'A rejected webhook must produce no state change.'
        );
    }

    /** @test */
    public function missing_signature_with_enforcement_is_rejected_403_without_state_change(): void
    {
        config(['signing-room.idura.webhook_enforce' => true]);

        $envelope = $this->createSentEnvelope();

        $this->postWebhook($this->payload(), null)
            ->assertStatus(403);

        $this->assertSame(
            EnvelopeStatus::Sent,
            $envelope->fresh()->status,
            'A signature-less webhook must produce no state change when enforcing.'
        );
    }

    /** @test */
    public function wrong_signature_without_enforcement_is_processed_and_logged(): void
    {
        Log::spy();

        $envelope = $this->createSentEnvelope();
        $payload  = $this->payload();

        $this->postWebhook($payload, 'this-is-not-a-valid-signature')
            ->assertOk();

        $this->assertSame(
            EnvelopeStatus::Expired,
            $envelope->fresh()->status,
            'With enforcement off, the webhook is still processed (in-flight orders).'
        );

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'signature missing or invalid'))
            ->once();
    }

    /** @test */
    public function missing_signature_without_enforcement_is_processed_and_logged(): void
    {
        Log::spy();

        $envelope = $this->createSentEnvelope();

        $this->postWebhook($this->payload(), null)
            ->assertOk();

        $this->assertSame(
            EnvelopeStatus::Expired,
            $envelope->fresh()->status,
        );

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'signature missing or invalid'))
            ->once();
    }

    /** @test */
    public function missing_secret_in_production_aborts_500(): void
    {
        config(['signing-room.idura.webhook_secret' => null]);
        app()->detectEnvironment(fn () => 'production');

        $this->createSentEnvelope();

        $this->postWebhook($this->payload(), null)
            ->assertStatus(500);
    }

    /** @test */
    public function missing_secret_outside_production_is_tolerated(): void
    {
        config(['signing-room.idura.webhook_secret' => null]);
        // Default test env is "testing".

        $envelope = $this->createSentEnvelope();

        $this->postWebhook($this->payload(), null)
            ->assertOk();

        $this->assertSame(
            EnvelopeStatus::Expired,
            $envelope->fresh()->status,
            'Outside production a missing secret is tolerated and the webhook processes.'
        );
    }
}
