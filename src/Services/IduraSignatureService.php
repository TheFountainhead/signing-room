<?php

namespace Fountainhead\SigningRoom\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class IduraSignatureService
{
    private string $endpoint;
    private ?string $clientId;
    private ?string $clientSecret;

    public function __construct()
    {
        $this->endpoint = config('signing-room.idura.endpoint', 'https://signatures-api.criipto.com/v1/graphql');
        $this->clientId = config('signing-room.idura.client_id');
        $this->clientSecret = config('signing-room.idura.client_secret');
    }

    /**
     * Check if the service is configured with valid credentials.
     */
    public function isConfigured(): bool
    {
        return $this->clientId !== null && $this->clientSecret !== null;
    }

    /**
     * Create a signature order with one PDF document.
     */
    public function createOrder(
        string $title,
        string $pdfBase64,
        string $documentTitle,
        string $webhookUrl,
        string $redirectUri,
        int $expiresInDays = 30,
    ): array {
        $acrValues = config('signing-room.idura.acr_values', ['urn:grn:authn:dk:mitid:low']);
        $language = config('signing-room.ui.language', 'DA_DK');
        $logo = config('signing-room.ui.logo');

        $mutation = <<<'GRAPHQL'
mutation CreateSignatureOrder($input: CreateSignatureOrderInput!) {
    createSignatureOrder(input: $input) {
        signatureOrder {
            id
            status
            documents {
                id
                title
            }
        }
    }
}
GRAPHQL;

        $uiInput = [
            'language' => $language,
            'signatoryRedirectUri' => $redirectUri,
            'disableRejection' => false,
        ];

        if ($logo) {
            $uiInput['logo'] = ['src' => $logo];
        }

        $variables = [
            'input' => [
                'title' => $title,
                'expiresInDays' => $expiresInDays,
                'documents' => [
                    [
                        'pdf' => [
                            'title' => $documentTitle,
                            'blob' => $pdfBase64,
                        ],
                    ],
                ],
                'evidenceProviders' => [
                    [
                        'criiptoVerify' => [
                            'acrValues' => $acrValues,
                            // TODO: Re-enable uniqueEvidenceKey for production
                            // 'uniqueEvidenceKey' => 'sub',
                        ],
                    ],
                ],
                'webhook' => [
                    'url' => $webhookUrl,
                    'validateConnectivity' => false,
                ],
                'ui' => $uiInput,
            ],
        ];

        $result = $this->query($mutation, $variables);

        return $result['createSignatureOrder']['signatureOrder'];
    }

    /**
     * Add signatories to an existing signature order.
     *
     * Each signatory should have: reference, role, signing_sequence,
     * and optionally cpr (for evidence validation) and preapproved.
     *
     * @return array Array of signatory objects with id, href, reference.
     */
    public function addSignatories(string $orderId, array $signatories): array
    {
        $mutation = <<<'GRAPHQL'
mutation AddSignatory($input: AddSignatoryInput!) {
    addSignatory(input: $input) {
        signatory {
            id
            href
            reference
            status
            role
        }
    }
}
GRAPHQL;

        $results = [];

        foreach ($signatories as $signatory) {
            $evidenceValidation = [];

            if (! empty($signatory['cpr'])) {
                $evidenceValidation[] = [
                    'key' => 'cprNumberIdentifier',
                    'value' => $signatory['cpr'],
                ];
            }

            $signatoryInput = [
                'signatureOrderId' => $orderId,
                'reference' => $signatory['reference'],
                'role' => strtoupper($signatory['role'] ?? 'SIGNER'),
                'signingSequence' => $signatory['signing_sequence'] ?? 1,
            ];

            if (! empty($evidenceValidation)) {
                $signatoryInput['evidenceValidation'] = $evidenceValidation;
            }

            if (isset($signatory['preapproved']) && $signatory['preapproved']) {
                $signatoryInput['preapproved'] = true;
            }

            $result = $this->query($mutation, ['input' => $signatoryInput]);
            $results[] = $result['addSignatory']['signatory'];
        }

        return $results;
    }

    /**
     * Close a completed signature order and retrieve the signed PAdES PDF.
     *
     * CRITICAL: Documents are deleted from Idura after close!
     */
    public function closeOrder(string $orderId, int $retainDays = 7): array
    {
        $mutation = <<<'GRAPHQL'
mutation CloseSignatureOrder($input: CloseSignatureOrderInput!) {
    closeSignatureOrder(input: $input) {
        signatureOrder {
            id
            status
            documents {
                id
                title
                blob
            }
            signatories {
                id
                reference
                status
                role
            }
        }
    }
}
GRAPHQL;

        $variables = [
            'input' => [
                'signatureOrderId' => $orderId,
                'retainDocumentsForDays' => $retainDays,
            ],
        ];

        $result = $this->query($mutation, $variables);

        return $result['closeSignatureOrder']['signatureOrder'];
    }

    /**
     * Cancel a signature order.
     */
    public function cancelOrder(string $orderId): void
    {
        $mutation = <<<'GRAPHQL'
mutation CancelSignatureOrder($input: CancelSignatureOrderInput!) {
    cancelSignatureOrder(input: $input) {
        signatureOrder {
            id
            status
        }
    }
}
GRAPHQL;

        $variables = [
            'input' => [
                'signatureOrderId' => $orderId,
            ],
        ];

        $this->query($mutation, $variables);
    }

    /**
     * Fetch the current status of a signature order (for fallback polling).
     */
    public function getOrderStatus(string $orderId): array
    {
        $query = <<<'GRAPHQL'
query GetSignatureOrder($id: ID!) {
    signatureOrder(id: $id) {
        id
        status
        signatories {
            id
            reference
            status
            role
            href
        }
        documents {
            id
            title
        }
    }
}
GRAPHQL;

        $result = $this->query($query, ['id' => $orderId]);

        return $result['signatureOrder'];
    }

    /**
     * Validate a PDF document before creating an order.
     *
     * @return array{valid: bool, errors: array, fixable: bool}
     */
    public function validateDocument(string $pdfBase64): array
    {
        $mutation = <<<'GRAPHQL'
mutation ValidateDocument($input: ValidateDocumentInput!) {
    validateDocument(input: $input) {
        valid
        errors
        fixable
    }
}
GRAPHQL;

        $variables = [
            'input' => [
                'pdf' => [
                    'blob' => $pdfBase64,
                ],
            ],
        ];

        $result = $this->query($mutation, $variables);

        return $result['validateDocument'];
    }

    /**
     * Execute a GraphQL query/mutation against the Idura Signatures API.
     *
     * @throws RuntimeException
     */
    private function query(string $query, array $variables = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Idura Signatures API credentials are not configured. Set IDURA_SIGNATURES_CLIENT_ID and IDURA_SIGNATURES_CLIENT_SECRET.');
        }

        $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
            ->timeout(30)
            ->post($this->endpoint, [
                'query' => $query,
                'variables' => $variables,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Idura API request failed with status {$response->status()}: {$response->body()}"
            );
        }

        $body = $response->json();

        if (! empty($body['errors'])) {
            $messages = collect($body['errors'])
                ->pluck('message')
                ->implode('; ');

            throw new RuntimeException("Idura GraphQL errors: {$messages}");
        }

        return $body['data'] ?? [];
    }
}
