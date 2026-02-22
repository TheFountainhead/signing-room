<?php

use Illuminate\Support\Facades\Route;

Route::middleware(config('signing-room.routes.admin_middleware', ['web', 'auth']))
    ->prefix(config('signing-room.routes.admin_prefix', 'admin'))
    ->name('signing-room.admin.')
    ->group(function () {
        Route::get('/', \Fountainhead\SigningRoom\Livewire\Admin\EnvelopeList::class)
            ->name('index');

        Route::get('/create', \Fountainhead\SigningRoom\Livewire\Admin\EnvelopeCreate::class)
            ->name('create');

        Route::get('/users', \Fountainhead\SigningRoom\Livewire\Admin\AdminUsers::class)
            ->name('users');

        Route::get('/{signingEnvelope:uuid}', \Fountainhead\SigningRoom\Livewire\Admin\EnvelopeShow::class)
            ->name('show');
    });
