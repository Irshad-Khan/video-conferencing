<?php

namespace App\Events;

use App\Models\Meeting;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Server-pushed host action (mute/remove/lock/unlock/end/guests toggle) delivered
 * to every client on the meeting's signaling channel — unlike the WebRTC/roster
 * whispers, this one actually needs the server as the trigger, since a removed or
 * force-muted participant can't be expected to whisper their own removal to
 * themselves. See docs/WEBRTC.md and ROADMAP.md §6.
 */
class HostControlEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public Meeting $meeting,
        public string $action,
        public array $data = [],
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("meeting.{$this->meeting->meeting_uuid}");
    }

    public function broadcastAs(): string
    {
        return 'host.control';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['action' => $this->action, ...$this->data];
    }
}
