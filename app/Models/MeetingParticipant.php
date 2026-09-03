<?php

namespace App\Models;

use Database\Factories\MeetingParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['meeting_id', 'user_id', 'guest_name', 'role', 'status', 'joined_at', 'left_at'])]
#[Hidden(['guest_token'])]
class MeetingParticipant extends Model
{
    /** @use HasFactory<MeetingParticipantFactory> */
    use HasFactory;

    public const ROLE_HOST = 'host';

    public const ROLE_CO_HOST = 'co_host';

    public const ROLE_PARTICIPANT = 'participant';

    public const ROLE_GUEST = 'guest';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_ADMITTED = 'admitted';

    public const STATUS_JOINED = 'joined';

    public const STATUS_LEFT = 'left';

    public const STATUS_REMOVED = 'removed';

    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'admitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Meeting, $this>
     */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Stable identifier used to address this participant on the Reverb presence
     * channel (WebRTC signaling target, whisper routing).
     */
    public function presenceId(): string
    {
        return $this->user_id ? "user-{$this->user_id}" : "guest-{$this->id}";
    }
}
