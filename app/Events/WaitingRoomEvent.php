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
 * Waiting-room lifecycle notification, broadcast in one of two directions:
 *
 * - To the host, on the main `meeting.{uuid}` channel (`forWaitingParticipant: false`,
 *   the default) — `action: 'participant-waiting'`/`'participant-left-waiting'`, fired
 *   from MeetingJoinController when someone lands in or leaves the waiting room, so a
 *   host with the room already open sees the waiting list update live.
 * - To the waiting participant themselves, on a dedicated `waiting-room.{uuid}` channel
 *   (`forWaitingParticipant: true`) — `action: 'admitted'`/`'rejected'`, fired from
 *   MeetingControlController::admit()/reject(). A separate channel is required here
 *   because a `waiting` participant is deliberately not authorized onto `meeting.{uuid}`
 *   itself (see routes/channels.php) — they haven't been let into the call yet.
 */
class WaitingRoomEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public Meeting $meeting,
        public string $action,
        public array $data = [],
        public bool $forWaitingParticipant = false,
    ) {}

    public function broadcastOn(): Channel
    {
        $channel = $this->forWaitingParticipant
            ? "waiting-room.{$this->meeting->meeting_uuid}"
            : "meeting.{$this->meeting->meeting_uuid}";

        return new PrivateChannel($channel);
    }

    public function broadcastAs(): string
    {
        return 'waiting-room';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['action' => $this->action, ...$this->data];
    }
}
