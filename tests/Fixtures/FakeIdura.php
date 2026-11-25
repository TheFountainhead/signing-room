<?php

namespace Fountainhead\SigningRoom\Tests\Fixtures;

use Fountainhead\SigningRoom\Services\IduraSignatureService;
use RuntimeException;

/**
 * In-memory Idura that enforces the rule that bit us in prod (kuvert 35):
 * closeSignatureOrder fails while any signatory with signatoryRole SIGNER has
 * not signed. A VIEWER does not block, matching Idura's documented behaviour.
 */
class FakeIdura extends IduraSignatureService
{
    /** @var array<string, array{id: string, reference: string, signatoryRole: string, status: string, signingSequence: int}> */
    public array $signatories = [];

    /** @var list<array> Raw inputs passed to addSignatories. */
    public array $addedInputs = [];

    public string $orderStatus = 'OPEN';

    public int $closeCalls = 0;

    public int $failNextCloses = 0;

    /** Blob Idura still holds after close (null = retention expired). */
    public ?string $retainedBlob = '%PDF-1.4 signeret';

    public int $documentFetches = 0;

    public bool $failEvidence = false;

    public function __construct() {}

    public function isConfigured(): bool
    {
        return true;
    }

    public function createOrder(
        string $title,
        string $pdfBase64,
        string $documentTitle,
        string $webhookUrl,
        string $redirectUri,
        int $expiresInDays = 30,
    ): array
    {
        return ['id' => 'SignatureOrder:fake', 'status' => 'OPEN', 'documents' => []];
    }

    public function addSignatories(string $orderId, array $signatories): array
    {
        $results = [];

        foreach ($signatories as $i => $signatory) {
            $this->addedInputs[] = $signatory;

            $id = 'Signatory:' . (count($this->signatories) + 1);
            $this->signatories[$id] = [
                'id' => $id,
                'reference' => $signatory['reference'],
                'signatoryRole' => strtoupper($signatory['signatory_role'] ?? 'SIGNER'),
                'status' => 'OPEN',
                'signingSequence' => $signatory['signing_sequence'] ?? 1,
            ];
            $results[] = ['id' => $id, 'href' => 'https://idura.test/sign/' . $id, 'reference' => $signatory['reference']];
        }

        return $results;
    }

    public function markSigned(string $signatoryId): void
    {
        $this->signatories[$signatoryId]['status'] = 'SIGNED';
    }

    public function closeOrder(string $orderId, int $retainDays = 7): array
    {
        $this->closeCalls++;

        if ($this->orderStatus !== 'OPEN') {
            throw new RuntimeException('Idura GraphQL errors: Signature order is not open');
        }

        if ($this->failNextCloses > 0) {
            $this->failNextCloses--;
            throw new RuntimeException('Idura API request failed with status 503: unavailable');
        }

        foreach ($this->signatories as $signatory) {
            if ($signatory['signatoryRole'] === 'SIGNER' && $signatory['status'] !== 'SIGNED') {
                throw new RuntimeException('Idura GraphQL errors: Signature order has open signatory');
            }
        }

        $this->orderStatus = 'CLOSED';

        return [
            'id' => $orderId,
            'status' => 'CLOSED',
            'documents' => [['id' => 'Document:1', 'title' => 'x', 'blob' => base64_encode('%PDF-1.4 signeret')]],
            'signatories' => array_values($this->signatories),
        ];
    }

    public function getOrderStatus(string $orderId): array
    {
        return [
            'id' => $orderId,
            'status' => $this->orderStatus,
            'signatories' => array_values($this->signatories),
            'documents' => [],
        ];
    }

    public function getOrderDocuments(string $orderId): array
    {
        $this->documentFetches++;

        return [
            'id' => $orderId,
            'status' => $this->orderStatus,
            'documents' => [['id' => 'Document:1', 'title' => 'x', 'blob' => $this->retainedBlob === null ? null : base64_encode($this->retainedBlob)]],
        ];
    }

    public function getSignatoryEvidence(string $signatoryId): ?string
    {
        if ($this->failEvidence) {
            throw new RuntimeException('Idura API request failed: evidence 503');
        }

        return null;
    }
}
