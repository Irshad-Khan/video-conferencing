<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\JoinMeetingRequest;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MeetingJoinController extends Controller
{
    public function lookup(string $code): JsonResponse
    {
        $meeting = Meeting::where('meeting_code', $code)->first();

        if (! $meeting) {
            return response()->json(['message' => 'Meeting not found.'], 404);
        }

        return response()->json(['data' => [
            'meeting_code' => $meeting->meeting_code,
            'title' => $meeting->title,
            'host_name' => $meeting->host->name,
            'status' => $meeting->status,
            'has_password' => $meeting->has_password,
            'allow_guests' => $meeting->allow_guests,
        ]]);
    }

    public function join(JoinMeetingRequest $request, string $code): JsonResponse
    {
        $meeting = Meeting::where('meeting_code', $code)->first();

        if (! $meeting) {
            return response()->json(['message' => 'Meeting not found.'], 404);
        }

        if (in_array($meeting->status, [Meeting::STATUS_ENDED, Meeting::STATUS_CANCELLED], true)) {
            return response()->json(['message' => "This meeting has been {$meeting->status}."], 409);
        }

        $user = $request->user();
        $isHost = $user && $user->id === $meeting->host_user_id;

        if ($meeting->password && ! $isHost) {
            if (! $request->filled('password') || ! Hash::check($request->input('password'), $meeting->password)) {
                return response()->json([
                    'message' => 'Incorrect meeting password.',
                    'errors' => ['password' => ['Incorrect meeting password.']],
                ], 422);
            }
        }

        if (! $user) {
            if (! $meeting->allow_guests) {
                return response()->json(['message' => 'This meeting does not allow guests. Please sign in.'], 403);
            }

            if (! $request->filled('guest_name')) {
                return response()->json([
                    'message' => 'A name is required to join as a guest.',
                    'errors' => ['guest_name' => ['The guest name field is required.']],
                ], 422);
            }
        }

        $participant = $user
            ? MeetingParticipant::where('meeting_id', $meeting->id)->where('user_id', $user->id)->first()
            : null;

        if ($participant && in_array($participant->status, [MeetingParticipant::STATUS_REMOVED, MeetingParticipant::STATUS_REJECTED], true)) {
            return response()->json(['message' => 'You are not permitted to join this meeting.'], 403);
        }

        $status = $isHost || ! $meeting->waiting_room_enabled
            ? MeetingParticipant::STATUS_JOINED
            : MeetingParticipant::STATUS_WAITING;

        $attributes = [
            'role' => $isHost ? MeetingParticipant::ROLE_HOST : ($user ? MeetingParticipant::ROLE_PARTICIPANT : MeetingParticipant::ROLE_GUEST),
            'status' => $status,
            'joined_at' => $status === MeetingParticipant::STATUS_JOINED ? now() : null,
            'left_at' => null,
        ];

        if ($participant) {
            $participant->forceFill($attributes)->save();
        } else {
            $participant = MeetingParticipant::create(array_merge($attributes, [
                'meeting_id' => $meeting->id,
                'user_id' => $user?->id,
                'guest_name' => $user ? null : $request->input('guest_name'),
            ]));
        }

        $guestToken = null;

        if (! $user) {
            $guestToken = Str::random(64);
            $participant->forceFill(['guest_token' => hash('sha256', $guestToken)])->save();
        }

        return response()->json([
            'data' => [
                'participant' => $participant,
                'meeting' => $meeting->only(['meeting_uuid', 'meeting_code', 'title', 'status']),
                'guest_token' => $guestToken,
                'presence_id' => $participant->presenceId(),
            ],
        ]);
    }

    public function leave(Request $request, string $code): JsonResponse
    {
        $meeting = Meeting::where('meeting_code', $code)->first();

        if (! $meeting) {
            return response()->json(['message' => 'Meeting not found.'], 404);
        }

        $user = $request->user();

        $participant = $user
            ? MeetingParticipant::where('meeting_id', $meeting->id)->where('user_id', $user->id)->first()
            : $this->resolveGuestParticipant($meeting, $request->input('guest_token'));

        if (! $participant) {
            return response()->json(['message' => 'You are not a participant of this meeting.'], 404);
        }

        $participant->forceFill(['status' => MeetingParticipant::STATUS_LEFT, 'left_at' => now()])->save();

        return response()->json(['data' => $participant]);
    }

    private function resolveGuestParticipant(Meeting $meeting, ?string $token): ?MeetingParticipant
    {
        if (! $token) {
            return null;
        }

        return MeetingParticipant::where('meeting_id', $meeting->id)
            ->where('guest_token', hash('sha256', $token))
            ->whereNull('left_at')
            ->first();
    }
}
