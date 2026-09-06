<?php

namespace Fountainhead\SigningRoom\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Enums\SigningPartyRole;
use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Fountainhead\SigningRoom\Notifications\DocumentReadyNotification;
use Fountainhead\SigningRoom\Notifications\EnvelopeCompletedNotification;
use Fountainhead\SigningRoom\Notifications\PartyRejectedNotification;
use Fountainhead\SigningRoom\Notifications\SigningReminderNotification;
use Fountainhead\SigningRoom\Services\SigningRoomService;
use Fountainhead\SigningRoom\Tests\TestCase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

/**
 * Kristian reported (jun 2026) that sending envelopes felt slow. Root cause:
 * notifications were sent synchronously inside the Livewire request with a
 * hardcoded usleep(600ms) between each send to respect Resend's 2 req/s rate
 * limit — 4 signers meant multiple seconds of dead waiting.
 *
 * Fix: all notifications are queued (ShouldQueue) and round notifications are
 * staggered with ->delay($index seconds) so the rate limit is still respected
 * on the worker instead of blocking the request.
 *
 * Queue routing: notifications intentionally use the DEFAULT queue. The
 * sign.frankston.io worker listens to `default,signing-room`, and the
 * Frankston-master fleet's Horizon supervisor-1 only listens to `default` —
 * a custom queue name would silently never be processed on the fleet.
 */
class QueuedNotificationsTest extends TestCase
{
    use RefreshDatabase;

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
            'role'                => SigningPartyRole::Signer->value,
            'signing_round'       => 1,
        ], $attributes));
    }

    #[Test]
    public function all_notifications_are_queued(): void
    {
        $envelope = $this->createEnvelope();
        $party = $this->createParty($envelope);

        $this->assertInstanceOf(ShouldQueue::class, new DocumentReadyNotification($envelope, $party));
        $this->assertInstanceOf(ShouldQueue::class, new EnvelopeCompletedNotification($envelope));
        $this->assertInstanceOf(ShouldQueue::class, new PartyRejectedNotification($envelope, $party));
        $this->assertInstanceOf(ShouldQueue::class, new SigningReminderNotification($envelope));
    }

    #[Test]
    public function notifications_use_the_default_queue(): void
    {
        $envelope = $this->createEnvelope();
        $party = $this->createParty($envelope);

        // The fleet's Horizon only processes the default queue — a custom
        // queue name here would mean signing emails silently never send.
        $this->assertNull((new DocumentReadyNotification($envelope, $party))->queue);
    }

    #[Test]
    public function round_notifications_are_staggered_to_respect_resend_rate_limit(): void
    {
        Notification::fake();

        $envelope = $this->createEnvelope();
        $first = $this->createParty($envelope, ['email' => 'first@example.com']);
        $second = $this->createParty($envelope, ['email' => 'second@example.com']);
        $third = $this->createParty($envelope, ['email' => 'third@example.com']);

        app(SigningRoomService::class)->notifyRoundParties($envelope, 1);

        Notification::assertSentTo($first, DocumentReadyNotification::class, fn ($notification) => $notification->delay === 0);
        Notification::assertSentTo($second, DocumentReadyNotification::class, fn ($notification) => $notification->delay === 1);
        Notification::assertSentTo($third, DocumentReadyNotification::class, fn ($notification) => $notification->delay === 2);
    }

    #[Test]
    public function round_notification_still_updates_party_status_immediately(): void
    {
        Notification::fake();

        $envelope = $this->createEnvelope();
        $party = $this->createParty($envelope);

        app(SigningRoomService::class)->notifyRoundParties($envelope, 1);

        $party->refresh();
        $this->assertEquals(SigningPartyStatus::Notified, $party->status);
        $this->assertNotNull($party->notified_at);
    }

    #[Test]
    public function viewer_parties_are_not_notified_in_rounds(): void
    {
        Notification::fake();

        $envelope = $this->createEnvelope();
        $viewer = $this->createParty($envelope, [
            'role' => SigningPartyRole::Viewer->value,
            'email' => 'viewer@example.com',
        ]);

        app(SigningRoomService::class)->notifyRoundParties($envelope, 1);

        Notification::assertNotSentTo($viewer, DocumentReadyNotification::class);
    }
}
