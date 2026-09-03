<?php

namespace Tests\Unit\Models;

use App\Models\MeetingParticipant;
use PHPUnit\Framework\TestCase;

/**
 * Framework-free — presenceId() is pure attribute logic, no database needed.
 */
class MeetingParticipantTest extends TestCase
{
    public function test_an_authenticated_participants_presence_id_is_derived_from_their_user_id(): void
    {
        $participant = (new MeetingParticipant)->forceFill(['id' => 7, 'user_id' => 42]);

        $this->assertSame('user-42', $participant->presenceId());
    }

    public function test_a_guests_presence_id_is_derived_from_their_own_row_id_not_user_id(): void
    {
        $participant = (new MeetingParticipant)->forceFill(['id' => 7, 'user_id' => null]);

        $this->assertSame('guest-7', $participant->presenceId());
    }
}
