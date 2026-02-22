<?php

namespace Fountainhead\SigningRoom;

use Fountainhead\SigningRoom\Jobs\ExpireSigningEnvelopes;
use Fountainhead\SigningRoom\Jobs\SendSigningReminders;
use Fountainhead\SigningRoom\Jobs\SyncIduraSignatureStatus;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class SigningRoomServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/signing-room.php', 'signing-room');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'signing-room');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Register Livewire components — fully qualified class names to avoid PHP namespace resolution issues
        Livewire::component('fountainhead.signing-room.livewire.portal.landing', \Fountainhead\SigningRoom\Livewire\Portal\Landing::class);
        Livewire::component('fountainhead.signing-room.livewire.portal.dashboard', \Fountainhead\SigningRoom\Livewire\Portal\Dashboard::class);
        Livewire::component('fountainhead.signing-room.livewire.portal.sign-document', \Fountainhead\SigningRoom\Livewire\Portal\SignDocument::class);
        Livewire::component('fountainhead.signing-room.livewire.portal.signing-complete', \Fountainhead\SigningRoom\Livewire\Portal\SigningComplete::class);
        Livewire::component('fountainhead.signing-room.livewire.admin.envelope-list', \Fountainhead\SigningRoom\Livewire\Admin\EnvelopeList::class);
        Livewire::component('fountainhead.signing-room.livewire.admin.envelope-create', \Fountainhead\SigningRoom\Livewire\Admin\EnvelopeCreate::class);
        Livewire::component('fountainhead.signing-room.livewire.admin.envelope-show', \Fountainhead\SigningRoom\Livewire\Admin\EnvelopeShow::class);

        $this->loadRoutesFrom(__DIR__ . '/../routes/portal.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/admin.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/webhook.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/signing-room.php' => config_path('signing-room.php'),
            ], 'signing-room-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/signing-room'),
            ], 'signing-room-views');

            $this->publishes([
                __DIR__ . '/../resources/assets' => public_path('assets'),
            ], 'signing-room-assets');
        }

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->job(new SendSigningReminders)->dailyAt('09:00');
            $schedule->job(new ExpireSigningEnvelopes)->dailyAt('00:15');
            $schedule->job(new SyncIduraSignatureStatus)->hourly();
        });
    }
}
