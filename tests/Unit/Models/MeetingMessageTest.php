<?php

namespace Tests\Unit\Models;

use App\Models\MeetingMessage;
use App\Models\MeetingParticipant;
use App\Models\User;
use PHPUnit\Framework\TestCase;

/**
 * Framework-free — senderName() itself touches only already-loaded relations, no
 * query. Every relation is set explicitly via setRelation() rather than left to lazy
 * -load (which would need a real database connection this test deliberately avoids).
 */
class MeetingMessageTest extends TestCase
{
    public function test_a_signed_in_users_message_uses_their_account_name(): void
    {
        $user = (new User)->forceFill(['name' => 'Ada Lovelace']);
        $message = (new MeetingMessage)
            ->setRelation('user', $user)
            ->setRelation('participant', null);

        $this->assertSame('Ada Lovelace', $message->senderName());
    }

    public function test_a_guests_message_uses_their_guest_name_when_theres_no_user(): void
    {
        $participant = (new MeetingParticipant)->forceFill(['guest_name' => 'Guest Grace']);
        $message = (new MeetingMessage)
            ->setRelation('user', null)
            ->setRelation('participant', $participant);

        $this->assertSame('Guest Grace', $message->senderName());
    }

    public function test_falls_back_to_a_generic_label_when_neither_relation_resolves(): void
    {
        $message = (new MeetingMessage)
            ->setRelation('user', null)
            ->setRelation('participant', null);

        $this->assertSame('Participant', $message->senderName());
    }

    public function test_prefers_the_user_name_over_the_participants_guest_name_when_both_are_present(): void
    {
        // Shouldn't normally happen (a signed-in user's row has no guest_name), but
        // senderName()'s ?? chain means the user always wins if somehow both resolve.
        $user = (new User)->forceFill(['name' => 'Ada Lovelace']);
        $participant = (new MeetingParticipant)->forceFill(['guest_name' => 'Guest Grace']);
        $message = (new MeetingMessage)
            ->setRelation('user', $user)
            ->setRelation('participant', $participant);

        $this->assertSame('Ada Lovelace', $message->senderName());
    }
}
