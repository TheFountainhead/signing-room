<?php

use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Fountainhead\SigningRoom\Models\SigningParty;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::middleware(config('signing-room.routes.portal_middleware', ['web']))
    ->prefix(config('signing-room.routes.portal_prefix', ''))
    ->name('signing-room.portal.')
    ->group(function () {
        Route::get('/', \Fountainhead\SigningRoom\Livewire\Portal\Landing::class)
            ->name('landing');

        Route::get('/dashboard', \Fountainhead\SigningRoom\Livewire\Portal\Dashboard::class)
            ->name('dashboard');

        Route::get('/sign/{signingParty:uuid}', \Fountainhead\SigningRoom\Livewire\Portal\SignDocument::class)
            ->middleware(\Fountainhead\SigningRoom\Http\Middleware\ValidateSigningToken::class)
            ->name('sign');

        Route::get('/complete', \Fountainhead\SigningRoom\Livewire\Portal\SigningComplete::class)
            ->name('signing-complete');

        Route::get('/download/{signingEnvelope:uuid}', function (SigningEnvelope $signingEnvelope) {
            $email = session('signing_room_email');

            if (! $email || ! $signingEnvelope->parties()->where('email', $email)->exists()) {
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
            // Allow access if: signing token is valid OR session email matches
            $email = session('signing_room_email');
            $hasSession = $email && $signingParty->email === $email;

            if (! $hasSession && ! $signingParty->isTokenValid()) {
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
            ]);
        })->name('pdf');

        Route::post('/logout', function () {
            session()->forget('signing_room_email');

            return redirect()->route('signing-room.portal.landing');
        })->name('logout');
    });
