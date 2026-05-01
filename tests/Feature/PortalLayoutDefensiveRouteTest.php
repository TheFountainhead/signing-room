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

    /**
     * @test
     *
     * FHT-1962 follow-up: the signing-complete page's MitID-login CTA for Tier 1
     * signers must remain guarded by Route::has() so a tenant that selectively
     * unregisters or overrides the auth.redirect route name does not crash the
     * post-signing render. Mirrors the landing-route guard convention.
     */
    public function signing_complete_blade_uses_route_has_guard_for_auth_redirect(): void
    {
        $blade = file_get_contents(
            dirname(__DIR__, 2) . '/resources/views/portal/signing-complete.blade.php'
        );

        $this->assertStringContainsString(
            "Route::has('signing-room.portal.auth.redirect')",
            $blade,
            'signing-complete view must guard the MitID-login CTA with Route::has() '
            .'on signing-room.portal.auth.redirect — defends against tenants where '
            .'a custom service-provider unregisters or overrides the route name.'
        );
    }

    /** @test */
    public function auth_redirect_route_is_registered_by_service_provider(): void
    {
        // Bilateral positive half — package's own routes/portal.php must register
        // this route name so the guard above does not silently route everyone to
        // the landing-only fallback when the package is loaded correctly.
        $this->assertTrue(
            Route::has('signing-room.portal.auth.redirect'),
            'SigningRoomServiceProvider must register signing-room.portal.auth.redirect '
            .'— if this fails, the package itself has lost the auth.redirect definition '
            .'(a regression upstream of the blade guard).'
        );
    }
}
