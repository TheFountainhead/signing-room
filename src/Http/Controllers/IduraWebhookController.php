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
use Illuminate\Support\Facades\Log;

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

        // Inden for kuverten: et signatory-id fra en anden ordre må ikke ramme.
        $party = $signatoryId
            ? SigningParty::where('signing_envelope_id', $envelope->id)->where('idura_signatory_id', $signatoryId)->first()
            : null;

        // Ukendt signatory (fx en slettet gammel kopimodtager hos Idura):
        // ignorér i stedet for en TypeError og en 500, som Idura gentager.
        if ($party === null && in_array($event, ['SIGNATORY_SIGNED', 'SIGNATORY_REJECTED'], true)) {
            return response()->json(['status' => 'ignored', 'reason' => 'unknown signatory'], 200);
        }

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
            // Fail closed in production: a missing secret means the webhook is
            // effectively unauthenticated, which must be a loud misconfiguration.
            if (app()->isProduction()) {
                abort(500, 'Signing-room webhook secret is not configured');
            }

            Log::warning('Signing-room webhook secret is not configured; skipping signature validation (non-production).');

            return;
        }

        $signature = $request->header('X-Criipto-Signature');

        // The secret is a base64 Blob; the HMAC key is its decoded bytes.
        $computed = base64_encode(hash_hmac('sha256', $request->getContent(), base64_decode($secret), true));

        if ($signature && hash_equals($computed, $signature)) {
            return;
        }

        // Orders created before the secret was registered never carry the
        // header. Until those have drained, enforce=false keeps them flowing
        // while still surfacing the gap (Log::error → Flare).
        if (config('signing-room.idura.webhook_enforce')) {
            abort(403, 'Invalid webhook signature');
        }

        Log::error('Signing-room webhook signature missing or invalid (enforcement disabled).', [
            'signatureOrderId' => $request->input('signatureOrderId'),
            'hasSignatureHeader' => $signature !== null,
        ]);
    }

    private function handleError(SigningEnvelope $envelope, ?SigningParty $party, array $data): void
    {
        // En sen eller gentaget fejl-webhook må ikke overskrive en underskrift.
        if ($party) {
            SigningParty::query()->whereKey($party->getKey())
                ->where('status', '!=', SigningPartyStatus::Signed->value)
                ->update(['status' => SigningPartyStatus::Error->value]);
        }
        $envelope->logEvent(SigningEventType::PartyError, $party, $data);
    }

    private function handleExpired(SigningEnvelope $envelope): void
    {
        app(SigningRoomService::class)->expireEnvelope($envelope);
    }

    private function handleViewed(SigningEnvelope $envelope, ?SigningParty $party): void
    {
        if ($party && ! $party->viewed_at) {
            // viewed_at registreres altid; status kun hvis parten ikke allerede
            // har underskrevet eller afvist (sen webhook).
            SigningParty::query()->whereKey($party->getKey())->update(['viewed_at' => now()]);
            SigningParty::query()->whereKey($party->getKey())
                ->whereNotIn('status', [SigningPartyStatus::Signed->value, SigningPartyStatus::Rejected->value])
                ->update(['status' => SigningPartyStatus::Viewed->value]);
        }
        $envelope->logEvent(SigningEventType::PartyViewed, $party);
    }
}
