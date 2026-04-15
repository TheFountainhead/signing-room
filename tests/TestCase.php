<?php

namespace Fountainhead\SigningRoom\Tests;

use Fountainhead\SigningRoom\SigningRoomServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            SigningRoomServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Use in-memory SQLite for fast tests
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Disable Idura so the scheduler booted() guard doesn't abort early
        $app['config']->set('signing-room.idura.client_id', null);

        // Set a known Criipto client_id so hasCriiptoVerify = true in Landing
        $app['config']->set('signing-room.criipto_verify.client_id', 'test-criipto-client');

        // Encryption key required for CPR mutator (32 random bytes, base64-encoded = AES-256 compatible)
        $app['config']->set('app.key', 'base64:xK+HZZkk5pubzaLOHrWwLYQNipW8jHI5BJHdmShF2Qo=');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
