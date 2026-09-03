<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function stats(): JsonResponse
    {
        return response()->json(['data' => [
            'users' => [
                'total' => User::count(),
                'admins' => User::where('is_admin', true)->count(),
                'disabled' => User::where('is_disabled', true)->count(),
            ],
            'meetings' => [
                'total' => Meeting::count(),
                'scheduled' => Meeting::where('status', Meeting::STATUS_SCHEDULED)->count(),
                'live' => Meeting::where('status', Meeting::STATUS_LIVE)->count(),
                'ended' => Meeting::where('status', Meeting::STATUS_ENDED)->count(),
                'cancelled' => Meeting::where('status', Meeting::STATUS_CANCELLED)->count(),
            ],
            'participants_total' => MeetingParticipant::count(),
        ]]);
    }

    public function users(): JsonResponse
    {
        $users = User::withCount('meetings')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'email', 'is_admin', 'is_disabled', 'created_at']);

        return response()->json(['data' => $users]);
    }

    public function disableUser(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot disable your own account.'], 409);
        }

        $user->forceFill(['is_disabled' => true])->save();

        return response()->json(['data' => $user]);
    }

    public function enableUser(User $user): JsonResponse
    {
        $user->forceFill(['is_disabled' => false])->save();

        return response()->json(['data' => $user]);
    }

    public function meetings(): JsonResponse
    {
        $meetings = Meeting::with('host:id,name,email')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $meetings]);
    }

    public function meetingParticipants(Meeting $meeting): JsonResponse
    {
        return response()->json(['data' => $meeting->participantHistory()]);
    }

    public function deleteMeeting(Meeting $meeting): JsonResponse
    {
        if ($meeting->status === Meeting::STATUS_LIVE) {
            return response()->json(['message' => 'A live meeting cannot be deleted. End it first.'], 409);
        }

        $meeting->delete();

        return response()->json(null, 204);
    }
}
