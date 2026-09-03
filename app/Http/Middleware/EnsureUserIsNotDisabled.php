<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects an already-authenticated session belonging to a user an admin has since
 * disabled. LoginRequest blocks a disabled account at login time, but a session that
 * was live before the admin action needs to be cut off on its next request too.
 */
class EnsureUserIsNotDisabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->is_disabled) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'This account has been disabled.'], 403);
        }

        return $next($request);
    }
}
