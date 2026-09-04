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
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            ->map(fn (MeetingMessage $message) => $message->toChatPayload($code));

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

        $attributes = [
            'meeting_id' => $meeting->id,
            'user_id' => $request->user()?->id,
            'meeting_participant_id' => $participant->id,
            'message' => $request->validated('message'),
            'created_at' => now(),
        ];

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attributes['attachment_path'] = $file->store("chat-attachments/{$meeting->id}", 'local');
            $attributes['attachment_original_name'] = $file->getClientOriginalName();
            $attributes['attachment_mime_type'] = $file->getMimeType();
            $attributes['attachment_size'] = $file->getSize();
        }

        $message = MeetingMessage::create($attributes);
        $message->setRelation('participant', $participant)->setRelation('user', $request->user());

        broadcast(new ChatMessageEvent($meeting, $message));

        return response()->json(['data' => $message->toChatPayload($code)], 201);
    }

    public function attachment(Request $request, string $code, MeetingMessage $message): StreamedResponse|JsonResponse
    {
        $meeting = Meeting::where('meeting_code', $code)->first();

        if (! $meeting || $message->meeting_id !== $meeting->id || ! $message->hasAttachment()) {
            abort(404);
        }

        if (! $this->resolveActiveParticipant($request, $meeting, $request->query('guest_token'))) {
            return response()->json(['message' => 'You are not a participant of this meeting.'], 403);
        }

        return Storage::disk('local')->response(
            $message->attachment_path,
            $message->attachment_original_name,
            ['X-Content-Type-Options' => 'nosniff'],
        );
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
