<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\HostControlEvent;
use App\Events\WaitingRoomEvent;
use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class MeetingControlController extends Controller
{
    public function lock(Meeting $meeting): JsonResponse
    {
        Gate::authorize('manageSettings', $meeting);

        $meeting->forceFill(['is_locked' => true])->save();
        broadcast(new HostControlEvent($meeting, 'lock'));

        return response()->json(['data' => $meeting]);
    }

    public function unlock(Meeting $meeting): JsonResponse
    {
        Gate::authorize('manageSettings', $meeting);

        $meeting->forceFill(['is_locked' => false])->save();
        broadcast(new HostControlEvent($meeting, 'unlock'));

        return response()->json(['data' => $meeting]);
    }

    public function enableGuests(Meeting $meeting): JsonResponse
    {
        Gate::authorize('manageSettings', $meeting);

        $meeting->forceFill(['allow_guests' => true])->save();
        broadcast(new HostControlEvent($meeting, 'guests-enabled'));

        return response()->json(['data' => $meeting]);
    }

    public function disableGuests(Meeting $meeting): JsonResponse
    {
        Gate::authorize('manageSettings', $meeting);

        $meeting->forceFill(['allow_guests' => false])->save();
        broadcast(new HostControlEvent($meeting, 'guests-disabled'));

        return response()->json(['data' => $meeting]);
    }

    public function enableWaitingRoom(Meeting $meeting): JsonResponse
    {
        Gate::authorize('manageSettings', $meeting);

        $meeting->forceFill(['waiting_room_enabled' => true])->save();
        broadcast(new HostControlEvent($meeting, 'waiting-room-enabled'));

        return response()->json(['data' => $meeting]);
    }

    public function disableWaitingRoom(Meeting $meeting): JsonResponse
    {
        Gate::authorize('manageSettings', $meeting);

        $meeting->forceFill(['waiting_room_enabled' => false])->save();
        broadcast(new HostControlEvent($meeting, 'waiting-room-disabled'));

        return response()->json(['data' => $meeting]);
    }

    public function waitingParticipants(Meeting $meeting): JsonResponse
    {
        Gate::authorize('manageParticipants', $meeting);

        $participants = $meeting->participants()
            ->where('status', MeetingParticipant::STATUS_WAITING)
            ->with('user:id,name')
            ->oldest()
            ->get()
            ->map(fn (MeetingParticipant $participant) => [
                'id' => $participant->id,
                'name' => $participant->guest_name ?? $participant->user?->name,
                'role' => $participant->role,
            ]);

        return response()->json(['data' => $participants]);
    }

    public function admitParticipant(Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        Gate::authorize('manageParticipants', $meeting);
        abort_if($participant->meeting_id !== $meeting->id, 404);

        if ($participant->status !== MeetingParticipant::STATUS_WAITING) {
            return response()->json(['message' => 'This participant is not currently waiting.'], 409);
        }

        $participant->forceFill(['status' => MeetingParticipant::STATUS_ADMITTED, 'admitted_at' => now()])->save();

        broadcast(new WaitingRoomEvent(
            $meeting,
            'admitted',
            ['target' => $participant->presenceId()],
            forWaitingParticipant: true,
        ));

        return response()->json(['data' => $participant]);
    }

    public function rejectParticipant(Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        Gate::authorize('manageParticipants', $meeting);
        abort_if($participant->meeting_id !== $meeting->id, 404);

        if ($participant->status !== MeetingParticipant::STATUS_WAITING) {
            return response()->json(['message' => 'This participant is not currently waiting.'], 409);
        }

        $participant->endSession(MeetingParticipant::STATUS_REJECTED);

        broadcast(new WaitingRoomEvent(
            $meeting,
            'rejected',
            ['target' => $participant->presenceId()],
            forWaitingParticipant: true,
        ));

        return response()->json(['data' => $participant]);
    }

    public function muteParticipant(Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        Gate::authorize('manageParticipants', $meeting);
        abort_if($participant->meeting_id !== $meeting->id, 404);

        if ($participant->status !== MeetingParticipant::STATUS_JOINED) {
            return response()->json(['message' => 'This participant is not currently in the call.'], 409);
        }

        broadcast(new HostControlEvent($meeting, 'mute', ['target' => $participant->presenceId()]));

        return response()->json(['data' => ['muted' => true]]);
    }

    public function promoteToCoHost(Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        Gate::authorize('manageCoHosts', $meeting);
        abort_if($participant->meeting_id !== $meeting->id, 404);

        if ($participant->role === MeetingParticipant::ROLE_HOST) {
            return response()->json(['message' => 'The host is already in charge.'], 409);
        }

        if ($participant->user_id === null) {
            return response()->json(['message' => 'A guest cannot be made a co-host.'], 422);
        }

        if ($participant->status !== MeetingParticipant::STATUS_JOINED) {
            return response()->json(['message' => 'This participant is not currently in the call.'], 409);
        }

        $participant->forceFill(['role' => MeetingParticipant::ROLE_CO_HOST])->save();
        broadcast(new HostControlEvent($meeting, 'co-host-promoted', ['target' => $participant->presenceId()]));

        return response()->json(['data' => $participant]);
    }

    public function demoteFromCoHost(Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        Gate::authorize('manageCoHosts', $meeting);
        abort_if($participant->meeting_id !== $meeting->id, 404);

        if ($participant->role !== MeetingParticipant::ROLE_CO_HOST) {
            return response()->json(['message' => 'This participant is not currently a co-host.'], 409);
        }

        $participant->forceFill(['role' => MeetingParticipant::ROLE_PARTICIPANT])->save();
        broadcast(new HostControlEvent($meeting, 'co-host-demoted', ['target' => $participant->presenceId()]));

        return response()->json(['data' => $participant]);
    }

    public function spotlightParticipant(Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        Gate::authorize('manageParticipants', $meeting);
        abort_if($participant->meeting_id !== $meeting->id, 404);

        if ($participant->status !== MeetingParticipant::STATUS_JOINED) {
            return response()->json(['message' => 'This participant is not currently in the call.'], 409);
        }

        broadcast(new HostControlEvent($meeting, 'spotlight', ['target' => $participant->presenceId()]));

        return response()->json(['data' => ['spotlighted' => true]]);
    }

    public function clearSpotlight(Meeting $meeting): JsonResponse
    {
        Gate::authorize('manageParticipants', $meeting);

        broadcast(new HostControlEvent($meeting, 'spotlight-cleared'));

        return response()->json(['data' => ['spotlighted' => false]]);
    }

    public function removeParticipant(Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        Gate::authorize('manageParticipants', $meeting);
        abort_if($participant->meeting_id !== $meeting->id, 404);

        if ($participant->role === MeetingParticipant::ROLE_HOST) {
            return response()->json(['message' => 'The host cannot be removed.'], 409);
        }

        if (in_array($participant->status, [MeetingParticipant::STATUS_LEFT, MeetingParticipant::STATUS_REMOVED], true)) {
            return response()->json(['message' => 'This participant has already left the meeting.'], 409);
        }

        $participant->endSession(MeetingParticipant::STATUS_REMOVED);
        broadcast(new HostControlEvent($meeting, 'remove', ['target' => $participant->presenceId()]));

        return response()->json(['data' => $participant]);
    }
}
