<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ChatMessageEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMeetingMessageRequest;
use App\Models\Meeting;
use App\Models\MeetingMessage;
use App\Models\MeetingParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeetingMessageController extends Controller
{
    public function index(Request $request, string $code): JsonResponse
    {
        $meeting = Meeting::where('meeting_code', $code)->first();

        if (! $meeting) {
            return response()->json(['message' => 'Meeting not found.'], 404);
        }

        if (! $this->resolveActiveParticipant($request, $meeting, $request->query('guest_token'))) {
            return response()->json(['message' => 'You are not a participant of this meeting.'], 403);
        }

        $messages = $meeting->messages()
            ->with(['user', 'participant'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (MeetingMessage $message) => [
                'id' => $message->id,
                'message' => $message->message,
                'sender_name' => $message->senderName(),
                'sender_role' => $message->participant?->role,
                'presence_id' => $message->participant?->presenceId(),
                'created_at' => $message->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $messages]);
    }

    public function store(StoreMeetingMessageRequest $request, string $code): JsonResponse
    {
        $meeting = Meeting::where('meeting_code', $code)->first();

        if (! $meeting) {
            return response()->json(['message' => 'Meeting not found.'], 404);
        }

        $participant = $this->resolveActiveParticipant($request, $meeting, $request->input('guest_token'));

        if (! $participant) {
            return response()->json(['message' => 'You are not a participant of this meeting.'], 403);
        }

        $message = MeetingMessage::create([
            'meeting_id' => $meeting->id,
            'user_id' => $request->user()?->id,
            'meeting_participant_id' => $participant->id,
            'message' => $request->validated('message'),
            'created_at' => now(),
        ]);

        $message->setRelation('participant', $participant)->setRelation('user', $request->user());

        broadcast(new ChatMessageEvent($meeting, $message));

        return response()->json(['data' => [
            'id' => $message->id,
            'message' => $message->message,
            'sender_name' => $message->senderName(),
            'sender_role' => $participant->role,
            'presence_id' => $participant->presenceId(),
            'created_at' => $message->created_at->toIso8601String(),
        ]], 201);
    }

    private function resolveActiveParticipant(Request $request, Meeting $meeting, ?string $guestToken): ?MeetingParticipant
    {
        $user = $request->user();

        $participant = $user
            ? MeetingParticipant::where('meeting_id', $meeting->id)->where('user_id', $user->id)->first()
            : $this->resolveGuestParticipant($meeting, $guestToken);

        if (! $participant || ! in_array($participant->status, [MeetingParticipant::STATUS_JOINED, MeetingParticipant::STATUS_ADMITTED], true)) {
            return null;
        }

        return $participant;
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
