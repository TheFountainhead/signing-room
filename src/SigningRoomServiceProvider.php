<?php

namespace Fountainhead\SigningRoom;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

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

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/signing-room.php' => config_path('signing-room.php'),
            ], 'signing-room-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/signing-room'),
            ], 'signing-room-views');
        }
    }
}
