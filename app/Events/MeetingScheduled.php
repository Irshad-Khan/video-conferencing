<?php

namespace App\Events;

use App\Models\Meeting;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a meeting is created with a future `scheduled_at`. Not broadcast over
 * Reverb (this is a server-side extension point, not client-facing signaling) and no
 * listeners are registered yet — the MVP has no email/SMS notifications (see
 * ROADMAP.md Phase 12). A future notification channel (e.g. a reminder email/SMS
 * listener) hooks in by listening for this event, with no controller changes needed.
 */
class MeetingScheduled
{
    use Dispatchable, SerializesModels;

    public function __construct(public Meeting $meeting) {}
}
