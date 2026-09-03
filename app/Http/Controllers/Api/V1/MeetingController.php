<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\HostControlEvent;
use App\Events\MeetingScheduled;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMeetingRequest;
use App\Http\Requests\UpdateMeetingRequest;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MeetingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $meetings = $request->user()
            ->meetings()
            ->orderByDesc('scheduled_at')
            ->get();

        return response()->json(['data' => $meetings]);
    }

    public function store(StoreMeetingRequest $request): JsonResponse
    {
        $meeting = $request->user()->meetings()->create($request->validated());

        if ($meeting->scheduled_at !== null) {
            event(new MeetingScheduled($meeting));
        }

        return response()->json(['data' => $meeting], 201);
    }

    public function show(Meeting $meeting): JsonResponse
    {
        Gate::authorize('view', $meeting);

        return response()->json(['data' => $meeting]);
    }

    public function update(UpdateMeetingRequest $request, Meeting $meeting): JsonResponse
    {
        Gate::authorize('update', $meeting);

        if ($meeting->status !== Meeting::STATUS_SCHEDULED) {
            return response()->json([
                'message' => 'Only a scheduled meeting can be edited.',
            ], 409);
        }

        $meeting->update($request->validated());

        return response()->json(['data' => $meeting]);
    }

    public function destroy(Meeting $meeting): JsonResponse
    {
        Gate::authorize('delete', $meeting);

        if ($meeting->status === Meeting::STATUS_LIVE) {
            return response()->json([
                'message' => 'A live meeting cannot be deleted. End it first.',
            ], 409);
        }

        $meeting->delete();

        return response()->json(null, 204);
    }

    public function cancel(Meeting $meeting): JsonResponse
    {
        Gate::authorize('manageLifecycle', $meeting);

        if ($meeting->status !== Meeting::STATUS_SCHEDULED) {
            return response()->json([
                'message' => 'Only a scheduled meeting can be cancelled.',
            ], 409);
        }

        $meeting->forceFill(['status' => Meeting::STATUS_CANCELLED])->save();

        return response()->json(['data' => $meeting]);
    }

    public function start(Meeting $meeting): JsonResponse
    {
        Gate::authorize('manageLifecycle', $meeting);

        if ($meeting->status !== Meeting::STATUS_SCHEDULED) {
            return response()->json([
                'message' => 'Only a scheduled meeting can be started.',
            ], 409);
        }

        $meeting->forceFill([
            'status' => Meeting::STATUS_LIVE,
            'started_at' => now(),
        ])->save();

        return response()->json(['data' => $meeting]);
    }

    public function end(Meeting $meeting): JsonResponse
    {
        Gate::authorize('manageLifecycle', $meeting);

        if ($meeting->status !== Meeting::STATUS_LIVE) {
            return response()->json([
                'message' => 'Only a live meeting can be ended.',
            ], 409);
        }

        $meeting->forceFill([
            'status' => Meeting::STATUS_ENDED,
            'ended_at' => now(),
        ])->save();

        // Anyone still present (or still waiting) never called /leave themselves — most
        // will have just been disconnected client-side by the broadcast below, some may
        // have silently dropped earlier. Finalize their records now so the meeting's
        // history is complete and durations are accurate, instead of leaving them stuck
        // with a null left_at forever.
        $meeting->participants()
            ->whereIn('status', [
                MeetingParticipant::STATUS_JOINED,
                MeetingParticipant::STATUS_ADMITTED,
                MeetingParticipant::STATUS_WAITING,
            ])
            ->get()
            ->each(fn (MeetingParticipant $participant) => $participant->endSession(MeetingParticipant::STATUS_LEFT));

        broadcast(new HostControlEvent($meeting, 'end'));

        return response()->json(['data' => $meeting]);
    }

    public function participants(Meeting $meeting): JsonResponse
    {
        Gate::authorize('view', $meeting);

        $participants = $meeting->participants()
            ->with('user:id,name')
            ->orderBy('joined_at')
            ->orderBy('created_at')
            ->get()
            ->map(fn (MeetingParticipant $participant) => [
                'id' => $participant->id,
                'name' => $participant->guest_name ?? $participant->user?->name,
                'role' => $participant->role,
                'status' => $participant->status,
                'joined_at' => $participant->joined_at,
                'left_at' => $participant->left_at,
                'duration_seconds' => $participant->duration_seconds,
            ]);

        return response()->json(['data' => $participants]);
    }
}
