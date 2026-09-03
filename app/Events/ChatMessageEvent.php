<?php

namespace App\Events;

use App\Models\Meeting;
use App\Models\MeetingMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Server-pushed chat message, delivered to every client on the meeting's signaling
 * channel once persisted — reuses the same private channel as WebRTC signaling and
 * host-control events rather than opening a second connection. See docs/WEBRTC.md.
 */
class ChatMessageEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Meeting $meeting,
        public MeetingMessage $message,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("meeting.{$this->meeting->meeting_uuid}");
    }

    public function broadcastAs(): string
    {
        return 'chat.message';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'message' => $this->message->message,
            'sender_name' => $this->message->senderName(),
            'sender_role' => $this->message->participant?->role,
            'presence_id' => $this->message->participant?->presenceId(),
            'created_at' => $this->message->created_at->toIso8601String(),
        ];
    }
}
