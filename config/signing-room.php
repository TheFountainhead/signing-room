<?php

return [
    'idura' => [
        'endpoint' => env('IDURA_SIGNATURES_ENDPOINT', 'https://signatures-api.criipto.com/v1/graphql'),
        'client_id' => env('IDURA_SIGNATURES_CLIENT_ID'),
        'client_secret' => env('IDURA_SIGNATURES_CLIENT_SECRET'),
        'webhook_secret' => env('IDURA_WEBHOOK_SECRET'),

        // Two-phase rollout for webhook HMAC enforcement. Orders created before
        // the secret was registered never carry X-Criipto-Signature, so phase 1
        // (false) only logs invalid signatures; flip to phase 2 (true) to
        // abort(403) once those in-flight orders have expired.
        'webhook_enforce' => env('IDURA_WEBHOOK_ENFORCE', false),

        'acr_values' => ['urn:grn:authn:dk:mitid:low'],
        'environment' => env('IDURA_ENVIRONMENT', 'TEST'),
    ],

    'criipto_verify' => [
        'client_id' => env('CRIIPTO_VERIFY_CLIENT_ID'),
        'client_secret' => env('CRIIPTO_VERIFY_CLIENT_SECRET'),
        'domain' => env('CRIIPTO_VERIFY_DOMAIN'),
    ],

    'defaults' => [
        'expires_in_days' => 30,
        'reminder_interval' => 7,
        'max_reminders' => 3,
        'retain_documents' => 7,
    ],

    'storage' => [
        'disk' => env('SIGNING_ROOM_DISK', 'local'),
        'path' => 'signing-room',
    ],

    'ui' => [
        'language' => 'DA_DK',
        'logo' => env('IDURA_LOGO_URL'),
    ],

    'routes' => [
        'portal_prefix' => '',
        'admin_prefix' => 'admin',
        'portal_middleware' => ['web'],
        'admin_middleware' => ['web', 'auth'],
        'webhook_middleware' => ['api'],
    ],

    // Callable that resolves branding for a given user_id.
    // Should return ['company_name' => string, 'logo_url' => string|null] or null.
    'branding_resolver' => null,

    // Model class used for folders (allows host app to provide its own)
    'folder_model' => 'App\\Models\\SigningRoomFolder',
];
