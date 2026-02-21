<?php

namespace Fountainhead\SigningRoom\Http\Controllers;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Enums\SigningEventType;
use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Fountainhead\SigningRoom\Services\SigningRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class IduraWebhookController extends Controller
{
    public function __invoke(Request $request, SigningRoomService $service): JsonResponse
    {
        $this->validateSignature($request);

        $event = $request->input('event');
        $signatoryId = $request->input('signatoryId');
        $orderId = $request->input('signatureOrderId');

        $envelope = SigningEnvelope::where('idura_signature_order_id', $orderId)->first();
        if (! $envelope) {
            return response()->json(['status' => 'ignored', 'reason' => 'unknown order'], 200);
        }

        $party = $signatoryId
            ? SigningParty::where('idura_signatory_id', $signatoryId)->first()
            : null;

        match ($event) {
            'SIGNATORY_SIGNED' => $service->handleSigned($party, $request->all()),
            'SIGNATORY_REJECTED' => $service->handleRejected($party, $request->input('reason')),
            'SIGNATORY_SIGN_ERROR' => $this->handleError($envelope, $party, $request->all()),
            'SIGNATURE_ORDER_EXPIRED' => $this->handleExpired($envelope),
            'SIGNATORY_SIGN_LINK_OPENED' => $this->handleViewed($envelope, $party),
            default => null,
        };

        return response()->json(['status' => 'ok']);
    }

    private function validateSignature(Request $request): void
    {
        $secret = config('signing-room.idura.webhook_secret');
        if (! $secret) {
            return;
        }

        $signature = $request->header('X-Criipto-Signature');
        $computed = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        abort_unless($signature && hash_equals($computed, $signature), 403, 'Invalid webhook signature');
    }

    private function handleError(SigningEnvelope $envelope, ?SigningParty $party, array $data): void
    {
        if ($party) {
            $party->update(['status' => SigningPartyStatus::Error]);
        }
        $envelope->logEvent(SigningEventType::PartyError, $party, $data);
    }

    private function handleExpired(SigningEnvelope $envelope): void
    {
        $envelope->update(['status' => EnvelopeStatus::Expired]);
        $envelope->logEvent(SigningEventType::EnvelopeExpired);
    }

    private function handleViewed(SigningEnvelope $envelope, ?SigningParty $party): void
    {
        if ($party && ! $party->viewed_at) {
            $party->update([
                'status' => SigningPartyStatus::Viewed,
                'viewed_at' => now(),
            ]);
        }
        $envelope->logEvent(SigningEventType::PartyViewed, $party);
    }
}
