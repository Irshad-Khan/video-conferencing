<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMeetingRequest;
use App\Http\Requests\UpdateMeetingRequest;
use App\Models\Meeting;
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

        return response()->json(['data' => $meeting]);
    }
}
