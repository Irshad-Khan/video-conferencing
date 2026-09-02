<?php

namespace Tests\Feature\Meetings;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingJoinTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_returns_public_meeting_info(): void
    {
        $host = User::factory()->create(['name' => 'Hosty McHostface']);
        $meeting = Meeting::factory()->for($host, 'host')->create(['password' => 'secret123']);

        $response = $this->getJson("/api/v1/meetings/lookup/{$meeting->meeting_code}");

        $response->assertStatus(200)
            ->assertJsonPath('data.title', $meeting->title)
            ->assertJsonPath('data.host_name', 'Hosty McHostface')
            ->assertJsonPath('data.has_password', true)
            ->assertJsonMissingPath('data.password');
    }

    public function test_lookup_returns_404_for_unknown_code(): void
    {
        $this->getJson('/api/v1/meetings/lookup/NOP-NOP-NOP')->assertStatus(404);
    }

    public function test_the_host_joins_their_own_meeting_as_host_and_bypasses_the_password(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create(['password' => 'secret123']);

        $response = $this->actingAs($host)->postJson("/api/v1/meetings/{$meeting->meeting_code}/join");

        $response->assertStatus(200)
            ->assertJsonPath('data.participant.role', MeetingParticipant::ROLE_HOST)
            ->assertJsonPath('data.participant.status', MeetingParticipant::STATUS_JOINED)
            ->assertJsonPath('data.guest_token', null);

        $this->assertDatabaseHas('meeting_participants', [
            'meeting_id' => $meeting->id,
            'user_id' => $host->id,
            'role' => MeetingParticipant::ROLE_HOST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);
    }

    public function test_an_authenticated_user_joins_as_a_participant(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create();
        $participantUser = User::factory()->create();

        $response = $this->actingAs($participantUser)->postJson("/api/v1/meetings/{$meeting->meeting_code}/join");

        $response->assertStatus(200)
            ->assertJsonPath('data.participant.role', MeetingParticipant::ROLE_PARTICIPANT)
            ->assertJsonPath('data.participant.status', MeetingParticipant::STATUS_JOINED);
    }

    public function test_an_authenticated_user_is_placed_in_the_waiting_room_when_enabled(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create(['waiting_room_enabled' => true]);
        $participantUser = User::factory()->create();

        $response = $this->actingAs($participantUser)->postJson("/api/v1/meetings/{$meeting->meeting_code}/join");

        $response->assertStatus(200)->assertJsonPath('data.participant.status', MeetingParticipant::STATUS_WAITING);
    }

    public function test_the_host_bypasses_the_waiting_room_in_their_own_meeting(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create(['waiting_room_enabled' => true]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/join")
            ->assertStatus(200)
            ->assertJsonPath('data.participant.status', MeetingParticipant::STATUS_JOINED);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create(['password' => 'secret123']);
        $participantUser = User::factory()->create();

        $this->actingAs($participantUser)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/join", ['password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_correct_password_allows_joining(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create(['password' => 'secret123']);
        $participantUser = User::factory()->create();

        $this->actingAs($participantUser)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/join", ['password' => 'secret123'])
            ->assertStatus(200)
            ->assertJsonPath('data.participant.status', MeetingParticipant::STATUS_JOINED);
    }

    public function test_a_guest_can_join_when_guests_are_allowed(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create(['allow_guests' => true]);

        $response = $this->postJson("/api/v1/meetings/{$meeting->meeting_code}/join", ['guest_name' => 'Curious George']);

        $response->assertStatus(200)
            ->assertJsonPath('data.participant.role', MeetingParticipant::ROLE_GUEST)
            ->assertJsonPath('data.participant.guest_name', 'Curious George')
            ->assertJsonPath('data.participant.status', MeetingParticipant::STATUS_JOINED);

        $this->assertIsString($response->json('data.guest_token'));
        $this->assertNotEmpty($response->json('data.guest_token'));
    }

    public function test_a_guest_is_rejected_when_the_meeting_disallows_guests(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create(['allow_guests' => false]);

        $this->postJson("/api/v1/meetings/{$meeting->meeting_code}/join", ['guest_name' => 'Curious George'])
            ->assertStatus(403);
    }

    public function test_a_guest_must_supply_a_name(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create(['allow_guests' => true]);

        $this->postJson("/api/v1/meetings/{$meeting->meeting_code}/join")
            ->assertStatus(422)
            ->assertJsonValidationErrors('guest_name');
    }

    public function test_joining_an_ended_meeting_is_rejected(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->ended()->for($host, 'host')->create();
        $participantUser = User::factory()->create();

        $this->actingAs($participantUser)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/join")
            ->assertStatus(409);
    }

    public function test_joining_a_cancelled_meeting_is_rejected(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->cancelled()->for($host, 'host')->create();
        $participantUser = User::factory()->create();

        $this->actingAs($participantUser)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/join")
            ->assertStatus(409);
    }

    public function test_joining_an_unknown_code_returns_404(): void
    {
        $participantUser = User::factory()->create();

        $this->actingAs($participantUser)
            ->postJson('/api/v1/meetings/NOP-NOP-NOP/join')
            ->assertStatus(404);
    }

    public function test_an_authenticated_participant_can_leave_and_rejoin_reusing_the_same_record(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create();
        $participantUser = User::factory()->create();

        $this->actingAs($participantUser)->postJson("/api/v1/meetings/{$meeting->meeting_code}/join")->assertStatus(200);

        $this->assertDatabaseCount('meeting_participants', 1);

        $this->actingAs($participantUser)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/leave")
            ->assertStatus(200)
            ->assertJsonPath('data.status', MeetingParticipant::STATUS_LEFT);

        $this->assertDatabaseHas('meeting_participants', [
            'meeting_id' => $meeting->id,
            'user_id' => $participantUser->id,
            'status' => MeetingParticipant::STATUS_LEFT,
        ]);

        $this->actingAs($participantUser)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/join")
            ->assertStatus(200)
            ->assertJsonPath('data.participant.status', MeetingParticipant::STATUS_JOINED);

        // Rejoining reuses the same row rather than creating a duplicate.
        $this->assertDatabaseCount('meeting_participants', 1);
    }

    public function test_a_removed_participant_cannot_rejoin(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create();
        $participantUser = User::factory()->create();

        MeetingParticipant::factory()->create([
            'meeting_id' => $meeting->id,
            'user_id' => $participantUser->id,
            'status' => MeetingParticipant::STATUS_REMOVED,
        ]);

        $this->actingAs($participantUser)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/join")
            ->assertStatus(403);
    }

    public function test_a_guest_can_leave_using_their_guest_token(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create(['allow_guests' => true]);

        $joinResponse = $this->postJson("/api/v1/meetings/{$meeting->meeting_code}/join", ['guest_name' => 'Curious George']);
        $guestToken = $joinResponse->json('data.guest_token');

        $this->postJson("/api/v1/meetings/{$meeting->meeting_code}/leave", ['guest_token' => $guestToken])
            ->assertStatus(200)
            ->assertJsonPath('data.status', MeetingParticipant::STATUS_LEFT);
    }

    public function test_leaving_without_a_valid_identity_returns_404(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create();

        $this->postJson("/api/v1/meetings/{$meeting->meeting_code}/leave")->assertStatus(404);
    }
}
