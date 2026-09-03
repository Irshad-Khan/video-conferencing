<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // General-purpose ceiling for every /api/v1 route (Phase 16 hardening — before
        // this, only a handful of routes had any throttle at all, leaving registration,
        // meeting-code lookup, and every auth:sanctum-protected route, including all of
        // meeting CRUD and the admin panel, completely unthrottled). Sensitive routes
        // (login, join, chat send, register) additionally carry their own tighter limit
        // on top of this one — see routes/api.php and LoginRequest.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Deliberately keyed by IP only, not the default user-id-or-IP: registering
        // authenticates the caller as the new user, so a by-user-id key would reset to
        // a fresh, unused bucket on every successful attempt — silently defeating any
        // cap on repeated registration. Caught by a test that fires 11 registrations in
        // a row (the 11th blocked) rather than trusting the reasoning alone.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
