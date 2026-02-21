<?php

return [
    'idura' => [
        'endpoint' => env('IDURA_SIGNATURES_ENDPOINT', 'https://signatures-api.criipto.com/v1/graphql'),
        'client_id' => env('IDURA_SIGNATURES_CLIENT_ID'),
        'client_secret' => env('IDURA_SIGNATURES_CLIENT_SECRET'),
        'webhook_secret' => env('IDURA_WEBHOOK_SECRET'),
        'acr_values' => ['urn:grn:authn:dk:mitid:low'],
        'environment' => env('IDURA_ENVIRONMENT', 'TEST'),
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
];
