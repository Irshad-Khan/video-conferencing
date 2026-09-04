<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['meeting_id', 'user_id', 'meeting_participant_id', 'message', 'attachment_path', 'attachment_original_name', 'attachment_mime_type', 'attachment_size'])]
class MeetingMessage extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'attachment_size' => 'integer',
        ];
    }

    public function hasAttachment(): bool
    {
        return ! is_null($this->attachment_path);
    }

    public function isImageAttachment(): bool
    {
        return str_starts_with($this->attachment_mime_type ?? '', 'image/');
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
     * @return BelongsTo<MeetingParticipant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'meeting_participant_id');
    }

    public function senderName(): string
    {
        return $this->user?->name ?? $this->participant?->guest_name ?? 'Participant';
    }

    /**
     * Shared shape for both the HTTP response (MeetingMessageController) and the
     * real-time broadcast (ChatMessageEvent) — deliberately one place, not two,
     * since Room.vue renders from the broadcast payload alone (the sender's own tab
     * never applies the HTTP response optimistically, see BUG-010 in BUGS.md), so a
     * field added to only one of the two copies would silently never reach the UI.
     *
     * @return array<string, mixed>
     */
    public function toChatPayload(string $meetingCode): array
    {
        return [
            'id' => $this->id,
            'message' => $this->message,
            'sender_name' => $this->senderName(),
            'sender_role' => $this->participant?->role,
            'presence_id' => $this->participant?->presenceId(),
            'created_at' => $this->created_at->toIso8601String(),
            'attachment' => $this->hasAttachment() ? [
                'url' => url("/api/v1/meetings/{$meetingCode}/messages/{$this->id}/attachment"),
                'name' => $this->attachment_original_name,
                'mime_type' => $this->attachment_mime_type,
                'size' => $this->attachment_size,
                'is_image' => $this->isImageAttachment(),
            ] : null,
        ];
    }
}
