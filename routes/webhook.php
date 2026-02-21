<?php

use Fountainhead\SigningRoom\Http\Controllers\IduraWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/idura-signatures', IduraWebhookController::class)
    ->middleware(config('signing-room.routes.webhook_middleware', ['api']))
    ->name('signing-room.webhook');
