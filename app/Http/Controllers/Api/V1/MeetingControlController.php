<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\HostControlEvent;
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

        $participant->forceFill(['status' => MeetingParticipant::STATUS_REMOVED, 'left_at' => now()])->save();
        broadcast(new HostControlEvent($meeting, 'remove', ['target' => $participant->presenceId()]));

        return response()->json(['data' => $participant]);
    }
}
