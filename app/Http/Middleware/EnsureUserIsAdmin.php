<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the entire /admin route group in one place, rather than a Gate::authorize()
 * call repeated per controller method — an admin panel is a flat role check, not a
 * per-resource ownership check, so a single group-level middleware guarantees every
 * admin route is covered by construction instead of relying on each method to
 * remember it (see ROADMAP.md Phase 15: "Server-side authorization on every admin
 * endpoint").
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_admin, 403, 'This action requires administrator access.');

        return $next($request);
    }
}
