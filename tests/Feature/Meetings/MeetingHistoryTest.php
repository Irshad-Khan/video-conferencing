<?php

namespace Tests\Feature\Meetings;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_leaving_accumulates_duration_seconds(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/api/v1/meetings/{$meeting->meeting_code}/join")->assertStatus(200);

        $participant = MeetingParticipant::where('meeting_id', $meeting->id)->where('user_id', $user->id)->firstOrFail();
        $participant->forceFill(['joined_at' => now()->subMinutes(10)])->save();

        $this->actingAs($user)->postJson("/api/v1/meetings/{$meeting->meeting_code}/leave")->assertStatus(200);

        $participant->refresh();
        $this->assertSame(MeetingParticipant::STATUS_LEFT, $participant->status);
        $this->assertNotNull($participant->left_at);
        $this->assertGreaterThanOrEqual(600, $participant->duration_seconds);
        $this->assertLessThan(605, $participant->duration_seconds);
    }

    public function test_duration_accumulates_across_multiple_reconnects(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $user = User::factory()->create();

        // First session: 5 minutes.
        $this->actingAs($user)->postJson("/api/v1/meetings/{$meeting->meeting_code}/join")->assertStatus(200);
        $participant = MeetingParticipant::where('meeting_id', $meeting->id)->where('user_id', $user->id)->firstOrFail();
        $participant->forceFill(['joined_at' => now()->subMinutes(5)])->save();
        $this->actingAs($user)->postJson("/api/v1/meetings/{$meeting->meeting_code}/leave")->assertStatus(200);
        $firstSessionDuration = $participant->fresh()->duration_seconds;
        $this->assertGreaterThanOrEqual(300, $firstSessionDuration);

        // Second session (reconnect, same row): another 3 minutes.
        $this->actingAs($user)->postJson("/api/v1/meetings/{$meeting->meeting_code}/join")->assertStatus(200);
        $participant->refresh()->forceFill(['joined_at' => now()->subMinutes(3)])->save();
        $this->actingAs($user)->postJson("/api/v1/meetings/{$meeting->meeting_code}/leave")->assertStatus(200);

        $participant->refresh();
        $this->assertGreaterThanOrEqual($firstSessionDuration + 180, $participant->duration_seconds);
        $this->assertSame(1, MeetingParticipant::where('meeting_id', $meeting->id)->where('user_id', $user->id)->count());
    }

    public function test_removing_a_participant_accumulates_their_duration(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $participant = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_JOINED,
            'joined_at' => now()->subMinutes(4),
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/remove")
            ->assertStatus(200);

        $participant->refresh();
        $this->assertSame(MeetingParticipant::STATUS_REMOVED, $participant->status);
        $this->assertGreaterThanOrEqual(240, $participant->duration_seconds);
    }

    public function test_rejecting_a_waiting_participant_adds_no_duration(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create(['waiting_room_enabled' => true]);
        $participant = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_WAITING,
            'joined_at' => null,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/reject")
            ->assertStatus(200);

        $participant->refresh();
        $this->assertSame(MeetingParticipant::STATUS_REJECTED, $participant->status);
        $this->assertSame(0, $participant->duration_seconds);
        $this->assertNotNull($participant->left_at);
    }

    public function test_ending_a_meeting_finalizes_every_still_present_participant(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create(['waiting_room_enabled' => true]);

        $joined = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_JOINED,
            'joined_at' => now()->subMinutes(6),
        ]);
        $admitted = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_ADMITTED,
            'joined_at' => null,
            'admitted_at' => now(),
        ]);
        $waiting = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_WAITING,
            'joined_at' => null,
        ]);
        $alreadyLeft = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_LEFT,
            'joined_at' => now()->subMinutes(20),
            'left_at' => now()->subMinutes(15),
            'duration_seconds' => 300,
        ]);

        $this->actingAs($host)->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/end")->assertStatus(200);

        $joined->refresh();
        $this->assertSame(MeetingParticipant::STATUS_LEFT, $joined->status);
        $this->assertNotNull($joined->left_at);
        $this->assertGreaterThanOrEqual(360, $joined->duration_seconds);

        $admitted->refresh();
        $this->assertSame(MeetingParticipant::STATUS_LEFT, $admitted->status);
        $this->assertNotNull($admitted->left_at);
        $this->assertSame(0, $admitted->duration_seconds);

        $waiting->refresh();
        $this->assertSame(MeetingParticipant::STATUS_LEFT, $waiting->status);
        $this->assertNotNull($waiting->left_at);
        $this->assertSame(0, $waiting->duration_seconds);

        // A participant who had already left before the meeting ended is untouched.
        $alreadyLeft->refresh();
        $this->assertSame(300, $alreadyLeft->duration_seconds);
    }

    public function test_the_host_can_list_the_full_participant_history(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->ended()->for($host, 'host')->create();
        $named = User::factory()->create(['name' => 'Ada Lovelace']);
        MeetingParticipant::factory()->for($meeting)->for($named)->create([
            'status' => MeetingParticipant::STATUS_LEFT,
            'joined_at' => now()->subHour(),
            'left_at' => now()->subMinutes(30),
            'duration_seconds' => 1800,
        ]);
        MeetingParticipant::factory()->for($meeting)->create([
            'user_id' => null,
            'guest_name' => 'Guest Grace',
            'role' => MeetingParticipant::ROLE_GUEST,
            'status' => MeetingParticipant::STATUS_LEFT,
            'joined_at' => now()->subMinutes(50),
            'left_at' => now()->subMinutes(40),
            'duration_seconds' => 600,
        ]);

        $response = $this->actingAs($host)
            ->getJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Ada Lovelace'));
        $this->assertTrue($names->contains('Guest Grace'));
        $response->assertJsonFragment(['name' => 'Ada Lovelace', 'duration_seconds' => 1800]);
        $response->assertJsonFragment(['name' => 'Guest Grace', 'duration_seconds' => 600]);
    }

    public function test_a_non_host_cannot_view_the_participant_history(): void
    {
        $host = User::factory()->create();
        $other = User::factory()->create();
        $meeting = Meeting::factory()->ended()->for($host, 'host')->create();

        $this->getJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants")->assertStatus(401);
        $this->actingAs($other)
            ->getJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants")
            ->assertStatus(403);
    }
}
