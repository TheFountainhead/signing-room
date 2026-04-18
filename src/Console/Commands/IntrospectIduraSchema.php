<?php

namespace Fountainhead\SigningRoom\Console\Commands;

use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Probe Idura's live GraphQL schema to verify the shape we need for
 * extracting CPR claims. Run once to confirm, then delete.
 */
class IntrospectIduraSchema extends Command
{
    protected $signature = 'signing-room:introspect-idura {envelope_uuid? : Envelope UUID to probe signatures for}';

    protected $description = 'Dump Idura GraphQL schema info for Signatory type + live signatures on an envelope';

    public function handle(): int
    {
        $endpoint = config('signing-room.idura.endpoint');
        $clientId = config('signing-room.idura.client_id');
        $secret = config('signing-room.idura.client_secret');

        if (! $clientId || ! $secret) {
            $this->error('Idura credentials not configured.');

            return self::FAILURE;
        }

        $this->info('=== 1. Signatory type fields ===');
        $this->dumpType('Signatory', $endpoint, $clientId, $secret);

        $this->newLine();
        $this->info('=== 2. Signature interface implementers ===');
        $this->dumpPossibleTypes('Signature', $endpoint, $clientId, $secret);

        $this->newLine();
        $this->info('=== 3. JWTSignature fields (should have claims) ===');
        $this->dumpType('JWTSignature', $endpoint, $clientId, $secret);

        if ($envelopeUuid = $this->argument('envelope_uuid')) {
            $envelope = SigningEnvelope::where('uuid', $envelopeUuid)->first();
            if (! $envelope || ! $envelope->idura_signature_order_id) {
                $this->warn("Envelope {$envelopeUuid} not found or missing idura_signature_order_id.");

                return self::SUCCESS;
            }

            $this->newLine();
            $this->info("=== 4. Live signatures for envelope {$envelope->title} ===");
            $this->dumpLiveSignatures($envelope->idura_signature_order_id, $endpoint, $clientId, $secret);
        }

        return self::SUCCESS;
    }

    private function dumpType(string $typeName, string $endpoint, string $id, string $secret): void
    {
        $query = 'query($n: String!) { __type(name: $n) { name kind fields { name type { name kind ofType { name kind } } } interfaces { name } } }';

        $this->post($endpoint, $id, $secret, $query, ['n' => $typeName]);
    }

    private function dumpPossibleTypes(string $typeName, string $endpoint, string $id, string $secret): void
    {
        $query = 'query($n: String!) { __type(name: $n) { name kind possibleTypes { name } } }';

        $this->post($endpoint, $id, $secret, $query, ['n' => $typeName]);
    }

    private function dumpLiveSignatures(string $orderId, string $endpoint, string $id, string $secret): void
    {
        $query = '
            query($id: ID!) {
                signatureOrder(id: $id) {
                    id
                    status
                    documents {
                        id
                        title
                        signatures {
                            __typename
                            signatory { id }
                        }
                    }
                }
            }
        ';

        $this->post($endpoint, $id, $secret, $query, ['id' => $orderId]);
    }

    private function post(string $endpoint, string $clientId, string $secret, string $query, array $variables): void
    {
        $response = Http::withBasicAuth($clientId, $secret)
            ->timeout(30)
            ->post($endpoint, [
                'query' => $query,
                'variables' => $variables,
            ]);

        if ($response->failed()) {
            $this->error("HTTP {$response->status()}: {$response->body()}");

            return;
        }

        $this->line(json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
