<?php

use Illuminate\Support\Facades\Route;

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
    });
