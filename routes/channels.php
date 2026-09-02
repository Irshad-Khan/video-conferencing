<?php

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

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
Broadcast::channel('meeting.{meetingUuid}', function ($principal, string $meetingUuid) {
    if (! $principal) {
        return false;
    }

    $meeting = Meeting::where('meeting_uuid', $meetingUuid)->first();

    if (! $meeting) {
        return false;
    }

    $participant = $principal instanceof MeetingParticipant
        ? ($principal->meeting_id === $meeting->id ? $principal : null)
        : MeetingParticipant::where('meeting_id', $meeting->id)->where('user_id', $principal->id)->first();

    return $participant && in_array($participant->status, [MeetingParticipant::STATUS_JOINED, MeetingParticipant::STATUS_ADMITTED], true);
});
