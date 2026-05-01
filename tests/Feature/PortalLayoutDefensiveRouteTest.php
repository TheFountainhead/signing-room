<?php

namespace Fountainhead\SigningRoom\Tests\Feature;

use Fountainhead\SigningRoom\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * Regression test for the Gesda 2026-04-30 incident: tenant deploy missing
 * the `signing-room.portal.landing` route name → every customer opening a
 * signing-link crashed with RouteNotFoundException at portal-layout render.
 *
 * Fix: layout uses Route::has() guard so view renders even when tenant lacks
 * the route registration. This test locks the guard in place against future
 * refactoring + asserts bilateral invariant (registered case still works).
 */
class PortalLayoutDefensiveRouteTest extends TestCase
{
    /** @test */
    public function portal_layout_blade_uses_route_has_guard_for_landing(): void
    {
        $blade = file_get_contents(
            dirname(__DIR__, 2) . '/resources/views/layouts/portal.blade.php'
        );

        // Negative half: blade must NOT call route('...landing') unguarded
        // The guard pattern: Route::has(...) ? route(...) : url('/')
        $this->assertStringContainsString(
            "Route::has('signing-room.portal.landing')",
            $blade,
            'Layout must wrap route(\'signing-room.portal.landing\') in Route::has() guard '
            .'to prevent RouteNotFoundException on tenants where the route is unregistered.'
        );

        $this->assertStringContainsString(
            "url('/')",
            $blade,
            'Layout must provide url(\'/\') fallback when landing route is unregistered.'
        );
    }

    /** @test */
    public function signing_complete_blade_uses_route_has_guard_for_landing(): void
    {
        $blade = file_get_contents(
            dirname(__DIR__, 2) . '/resources/views/portal/signing-complete.blade.php'
        );

        $this->assertStringContainsString(
            "Route::has('signing-room.portal.landing')",
            $blade,
            'signing-complete view must guard the "Til forsiden" link with Route::has().'
        );
    }

    /** @test */
    public function landing_route_is_registered_by_service_provider(): void
    {
        // Bilateral positive half: in environments where the package is loaded
        // properly (the canonical case), the route MUST be registered. This guards
        // against accidental removal of the route definition itself, which would
        // make the defensive guard above silently route everyone to url('/') even
        // for tenants that should have a landing page.
        $this->assertTrue(
            Route::has('signing-room.portal.landing'),
            'SigningRoomServiceProvider must register signing-room.portal.landing — '
            .'if this fails, the package itself has lost the landing-route definition '
            .'(a regression upstream of the layout guard).'
        );
    }
}
