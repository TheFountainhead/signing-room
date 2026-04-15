<?php

use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::middleware(config('signing-room.routes.portal_middleware', ['web']))
    ->prefix(config('signing-room.routes.portal_prefix', ''))
    ->name('signing-room.portal.')
    ->group(function () {
        // MitID auth routes (throttled to prevent Criipto quota abuse)
        Route::middleware('throttle:10,1')->group(function () {
            Route::get('/auth/criipto', [\Fountainhead\SigningRoom\Http\Controllers\CriiptoAuthController::class, 'redirect'])
                ->name('auth.redirect');

            Route::get('/auth/criipto/callback', [\Fountainhead\SigningRoom\Http\Controllers\CriiptoAuthController::class, 'callback'])
                ->name('auth.callback');
        });

        Route::get('/', \Fountainhead\SigningRoom\Livewire\Portal\Landing::class)
            ->name('landing');

        Route::get('/dashboard', \Fountainhead\SigningRoom\Livewire\Portal\Dashboard::class)
            ->name('dashboard');

        Route::get('/sign/{signingParty:uuid}', \Fountainhead\SigningRoom\Livewire\Portal\SignDocument::class)
            ->middleware(\Fountainhead\SigningRoom\Http\Middleware\ValidateSigningToken::class)
            ->name('sign');

        Route::get('/complete', \Fountainhead\SigningRoom\Livewire\Portal\SigningComplete::class)
            ->name('signing-complete');

        Route::get('/terms', \Fountainhead\SigningRoom\Livewire\Portal\Terms::class)
            ->name('terms');

        Route::get('/privacy', \Fountainhead\SigningRoom\Livewire\Portal\Privacy::class)
            ->name('privacy');

        Route::get('/dpa', \Fountainhead\SigningRoom\Livewire\Portal\DataProcessingAgreement::class)
            ->name('dpa');

        Route::get('/download/{signingEnvelope:uuid}', function (SigningEnvelope $signingEnvelope) {
            // Allow access via portal session OR authenticated admin user
            $cprHash = session('signing_room_cpr');
            $hasPortalSession = $cprHash && $signingEnvelope->parties()->where('cpr_hash', $cprHash)->exists();
            $hasAuthSession = auth()->check() && $signingEnvelope->parties()->where('email', auth()->user()->email)->exists();

            if (! $hasPortalSession && ! $hasAuthSession) {
                abort(403, 'Du har ikke adgang til dette dokument.');
            }

            if (! $signingEnvelope->signed_document) {
                abort(404, 'Det signerede dokument er ikke tilgængeligt endnu.');
            }

            $disk = Storage::disk(config('signing-room.storage.disk', 'local'));
            $filename = str($signingEnvelope->title)->slug() . '-signeret.pdf';

            return $disk->download($signingEnvelope->signed_document, $filename);
        })->name('download');

        Route::get('/pdf/{signingParty:uuid}', function (SigningParty $signingParty) {
            // Allow access if: CPR session matches OR signing token is valid
            $cprHash = session('signing_room_cpr');
            $hasCprSession = $cprHash && $signingParty->cpr_hash === $cprHash;

            $token = request()->query('token');
            $hasValidToken = $token && hash_equals($signingParty->signing_token, $token);

            if (! $hasCprSession && ! $hasValidToken) {
                abort(403);
            }

            $envelope = $signingParty->envelope;
            $document = $envelope->signed_document ?? $envelope->original_document;

            if (! $document) {
                abort(404);
            }

            $disk = Storage::disk(config('signing-room.storage.disk', 'local'));

            return response($disk->get($document), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline',
                'X-Frame-Options' => 'SAMEORIGIN',
                'Content-Security-Policy' => "frame-ancestors 'self'",
            ]);
        })->name('pdf');

        Route::post('/sign-out', [\Fountainhead\SigningRoom\Http\Controllers\CriiptoAuthController::class, 'logout'])
            ->name('logout');
    });
