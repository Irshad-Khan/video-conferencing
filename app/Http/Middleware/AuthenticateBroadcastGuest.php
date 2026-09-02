<?php

namespace App\Http\Middleware;

use App\Models\MeetingParticipant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBroadcastGuest
{
    /**
     * Resolve a guest's identity from their meeting-scoped token for Reverb channel
     * authorization, since guests never have a Sanctum session to authenticate with.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() && $token = $request->header('X-Guest-Token')) {
            $participant = MeetingParticipant::query()
                ->where('guest_token', hash('sha256', $token))
                ->whereNotIn('status', [
                    MeetingParticipant::STATUS_LEFT,
                    MeetingParticipant::STATUS_REMOVED,
                    MeetingParticipant::STATUS_REJECTED,
                ])
                ->first();

            if ($participant) {
                $request->setUserResolver(fn () => $participant);
            }
        }

        return $next($request);
    }
}
