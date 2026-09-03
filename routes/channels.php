<?php

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Resolves $principal (an authenticated User, or a MeetingParticipant for a guest
 * — see AuthenticateBroadcastGuest) to their participant row in $meeting, regardless
 * of status. Shared by both channel checks below, which then apply their own,
 * different status rules on top. A closure (not a named function) because this file
 * can be required more than once within a single PHP process (e.g. across tests).
 */
$resolveMeetingParticipant = function ($principal, Meeting $meeting): ?MeetingParticipant {
    return $principal instanceof MeetingParticipant
        ? ($principal->meeting_id === $meeting->id ? $principal : null)
        : MeetingParticipant::where('meeting_id', $meeting->id)->where('user_id', $principal->id)->first();
};

/**
 * WebRTC signaling channel, one per meeting (private, not presence — a numeric
 * Pusher/Reverb presence "user_id" derived from Eloquent getAuthIdentifier() would
 * collide between a User row and a guest MeetingParticipant row sharing the same id).
 * Offer/answer/ICE and roster (who's in the call) all travel as client whispers on
 * this channel — see docs/WEBRTC.md.
 *
 * $principal is either the authenticated User (session) or a MeetingParticipant
 * (guest, resolved from their one-time token by AuthenticateBroadcastGuest).
 */
Broadcast::channel('meeting.{meetingUuid}', function ($principal, string $meetingUuid) use ($resolveMeetingParticipant) {
    if (! $principal) {
        return false;
    }

    $meeting = Meeting::where('meeting_uuid', $meetingUuid)->first();

    if (! $meeting) {
        return false;
    }

    $participant = $resolveMeetingParticipant($principal, $meeting);

    return $participant && in_array($participant->status, [MeetingParticipant::STATUS_JOINED, MeetingParticipant::STATUS_ADMITTED], true);
});

/**
 * A participant sitting in the waiting room can't authorize onto meeting.{uuid}
 * above (they haven't been let into the call yet), but still needs a way to learn
 * they've been admitted/rejected in real time — see App\Events\WaitingRoomEvent
 * and docs/WEBRTC.md#waiting-room-signaling. Any participant of this meeting may
 * subscribe, regardless of status; the event itself targets a specific presenceId.
 */
Broadcast::channel('waiting-room.{meetingUuid}', function ($principal, string $meetingUuid) use ($resolveMeetingParticipant) {
    if (! $principal) {
        return false;
    }

    $meeting = Meeting::where('meeting_uuid', $meetingUuid)->first();

    if (! $meeting) {
        return false;
    }

    return (bool) $resolveMeetingParticipant($principal, $meeting);
});
