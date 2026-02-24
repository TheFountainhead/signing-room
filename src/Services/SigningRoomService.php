<?php

namespace Fountainhead\SigningRoom\Services;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Enums\SigningEventType;
use Fountainhead\SigningRoom\Enums\SigningPartyRole;
use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Fountainhead\SigningRoom\Notifications\DocumentReadyNotification;
use Fountainhead\SigningRoom\Notifications\EnvelopeCompletedNotification;
use Fountainhead\SigningRoom\Notifications\PartyRejectedNotification;
use Illuminate\Support\Facades\Storage;

class SigningRoomService
{
    public function __construct(
        private IduraSignatureService $idura,
    ) {}

    /**
     * Create a new signing envelope with parties.
     */
    public function createEnvelope(
        string $title,
        string $pdfPath,
        array $parties,
        ?string $description = null,
        ?int $expiresInDays = null,
        ?int $reminderInterval = null,
        ?int $createdBy = null,
    ): SigningEnvelope {
        $expiresInDays ??= config('signing-room.defaults.expires_in_days', 30);
        $reminderInterval ??= config('signing-room.defaults.reminder_interval', 7);

        $totalRounds = max(array_column($parties, 'signing_round') ?: [1]);

        $envelope = SigningEnvelope::create([
            'title' => $title,
            'description' => $description,
            'status' => EnvelopeStatus::Draft,
            'original_document' => $pdfPath,
            'total_rounds' => $totalRounds,
            'current_round' => 1,
            'expires_at' => now()->addDays($expiresInDays),
            'reminder_interval' => $reminderInterval,
            'created_by' => $createdBy,
        ]);

        foreach ($parties as $partyData) {
            $envelope->parties()->create([
                'name' => $partyData['name'],
                'email' => $partyData['email'],
                'phone' => $partyData['phone'] ?? null,
                'cpr_last_four' => $partyData['cpr_last_four'] ?? null,
                'role' => $partyData['role'] ?? SigningPartyRole::Signer->value,
                'signing_round' => $partyData['signing_round'] ?? 1,
                'status' => SigningPartyStatus::Pending,
            ]);
        }

        $envelope->logEvent(SigningEventType::EnvelopeCreated);

        return $envelope->load('parties');
    }

    /**
     * Send an envelope to Idura and notify first-round parties.
     */
    public function sendEnvelope(SigningEnvelope $envelope): SigningEnvelope
    {
        $disk = Storage::disk(config('signing-room.storage.disk', 'local'));
        $pdfContents = $disk->get($envelope->original_document);
        $pdfBase64 = base64_encode($pdfContents);

        $webhookUrl = route('signing-room.webhook');
        $redirectUri = route('signing-room.portal.signing-complete');

        // Create signature order at Idura
        $order = $this->idura->createOrder(
            title: $envelope->title,
            pdfBase64: $pdfBase64,
            documentTitle: $envelope->title,
            webhookUrl: $webhookUrl,
            redirectUri: $redirectUri,
            expiresInDays: (int) now()->diffInDays($envelope->expires_at),
        );

        $envelope->update([
            'idura_signature_order_id' => $order['id'],
        ]);

        // Add all parties as signatories
        $signatories = $envelope->parties->map(fn (SigningParty $party) => [
            'reference' => $party->uuid,
            'role' => strtoupper($party->role instanceof SigningPartyRole ? $party->role->value : $party->role),
            'signing_sequence' => $party->signing_round,
            'cpr' => $party->cpr_last_four ?: null,
        ])->all();

        $iduraSignatories = $this->idura->addSignatories($order['id'], $signatories);

        // Map Idura signatory IDs and signing links back to our parties
        foreach ($iduraSignatories as $iduraSignatory) {
            $party = $envelope->parties
                ->firstWhere('uuid', $iduraSignatory['reference']);

            if ($party) {
                $party->update([
                    'idura_signatory_id' => $iduraSignatory['id'],
                    'idura_signatory_href' => $iduraSignatory['href'] ?? null,
                ]);
            }
        }

        $envelope->update([
            'status' => EnvelopeStatus::Sent,
        ]);

        $envelope->logEvent(SigningEventType::EnvelopeSent);

        // Notify first-round parties
        $this->notifyRoundParties($envelope, 1);

        return $envelope->fresh('parties');
    }

    /**
     * Handle a party signing event from the webhook.
     */
    public function handleSigned(SigningParty $party, ?array $signatureData = null): void
    {
        $party->update([
            'status' => SigningPartyStatus::Signed,
            'signed_at' => now(),
            'signature_data' => $signatureData,
        ]);

        $envelope = $party->envelope;

        $envelope->logEvent(SigningEventType::PartySigned, $party);

        if ($envelope->isAllRoundsComplete()) {
            $this->completeEnvelope($envelope);
        } elseif ($envelope->isCurrentRoundComplete()) {
            $this->advanceRound($envelope);
        } else {
            $envelope->update([
                'status' => EnvelopeStatus::PartiallySigned,
            ]);
        }
    }

    /**
     * Handle a party rejection event from the webhook.
     */
    public function handleRejected(SigningParty $party, ?string $reason = null): void
    {
        $party->update([
            'status' => SigningPartyStatus::Rejected,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $envelope = $party->envelope;

        $envelope->logEvent(SigningEventType::PartyRejected, $party, [
            'reason' => $reason,
        ]);

        // Notify the envelope creator
        $this->notifyCreator($envelope, new PartyRejectedNotification($envelope, $party));
    }

    /**
     * Notify all signer parties in a given round.
     */
    public function notifyRoundParties(SigningEnvelope $envelope, int $round): void
    {
        $parties = $envelope->parties()
            ->where('signing_round', $round)
            ->where('role', SigningPartyRole::Signer->value)
            ->get();

        foreach ($parties as $index => $party) {
            // Resend rate limit: 2 requests/sec — add delay between emails
            if ($index > 0) {
                usleep(600_000); // 600ms
            }

            $party->notify(new DocumentReadyNotification($envelope, $party));

            $party->update([
                'status' => SigningPartyStatus::Notified,
                'notified_at' => now(),
            ]);

            $envelope->logEvent(SigningEventType::PartyNotified, $party);
        }
    }

    /**
     * Validate a PDF document before creating a signature order.
     */
    public function validateDocument(string $pdfBase64): array
    {
        return $this->idura->validateDocument($pdfBase64);
    }

    /**
     * Cancel an envelope, optionally cancelling at Idura as well.
     */
    public function cancelEnvelope(SigningEnvelope $envelope, ?string $reason = null): void
    {
        if ($envelope->idura_signature_order_id) {
            $this->idura->cancelOrder($envelope->idura_signature_order_id);
        }

        $envelope->update([
            'status' => EnvelopeStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $envelope->logEvent(SigningEventType::EnvelopeCancelled, metadata: [
            'reason' => $reason,
        ]);
    }

    /**
     * Advance to the next signing round.
     */
    private function advanceRound(SigningEnvelope $envelope): void
    {
        $nextRound = $envelope->current_round + 1;

        $envelope->update([
            'current_round' => $nextRound,
        ]);

        $envelope->logEvent(SigningEventType::RoundAdvanced, metadata: [
            'round' => $nextRound,
        ]);

        $this->notifyRoundParties($envelope, $nextRound);
    }

    /**
     * Complete an envelope: close the Idura order and save the signed PDF.
     */
    private function completeEnvelope(SigningEnvelope $envelope): void
    {
        $retainDays = config('signing-room.defaults.retain_documents', 7);

        $order = $this->idura->closeOrder(
            $envelope->idura_signature_order_id,
            $retainDays,
        );

        // Save the signed PDF from the first document's blob
        if (! empty($order['documents'][0]['blob'])) {
            $signedPdf = base64_decode($order['documents'][0]['blob']);
            $storagePath = config('signing-room.storage.path', 'signing-room')
                . '/' . $envelope->uuid . '/signed.pdf';

            $disk = Storage::disk(config('signing-room.storage.disk', 'local'));
            $disk->put($storagePath, $signedPdf);

            $envelope->update([
                'signed_document' => $storagePath,
            ]);
        }

        $envelope->update([
            'status' => EnvelopeStatus::Completed,
            'completed_at' => now(),
        ]);

        $envelope->logEvent(SigningEventType::EnvelopeCompleted);

        // Notify all parties that the envelope is completed
        foreach ($envelope->parties as $party) {
            $party->notify(new EnvelopeCompletedNotification($envelope));
        }

        // Also notify the creator
        $this->notifyCreator($envelope, new EnvelopeCompletedNotification($envelope));
    }

    /**
     * Send a notification to the envelope creator via anonymous notifiable.
     */
    private function notifyCreator(SigningEnvelope $envelope, $notification): void
    {
        if (! $envelope->created_by) {
            return;
        }

        $creator = \App\Models\User::find($envelope->created_by);

        if ($creator) {
            $creator->notify($notification);
        }
    }
}
