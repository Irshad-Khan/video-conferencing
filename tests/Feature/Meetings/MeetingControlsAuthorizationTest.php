<?php

namespace Tests\Feature\Meetings;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingControlsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_cannot_call_any_host_control_endpoint(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $participant = MeetingParticipant::factory()->for($meeting)->create();

        $this->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/lock")->assertStatus(401);
        $this->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/unlock")->assertStatus(401);
        $this->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/guests/enable")->assertStatus(401);
        $this->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/guests/disable")->assertStatus(401);
        $this->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/promote")->assertStatus(401);
        $this->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/demote")->assertStatus(401);
        $this->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/mute")->assertStatus(401);
        $this->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/spotlight")->assertStatus(401);
        $this->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/spotlight/clear")->assertStatus(401);
        $this->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/remove")->assertStatus(401);
    }

    public function test_a_non_host_participant_cannot_call_any_host_control_endpoint(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $stranger = User::factory()->create();
        $participant = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($stranger)->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/lock")->assertStatus(403);
        $this->actingAs($stranger)->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/unlock")->assertStatus(403);
        $this->actingAs($stranger)->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/guests/enable")->assertStatus(403);
        $this->actingAs($stranger)->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/guests/disable")->assertStatus(403);
        $this->actingAs($stranger)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/promote")
            ->assertStatus(403);
        $this->actingAs($stranger)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/demote")
            ->assertStatus(403);
        $this->actingAs($stranger)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/mute")
            ->assertStatus(403);
        $this->actingAs($stranger)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/spotlight")
            ->assertStatus(403);
        $this->actingAs($stranger)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/spotlight/clear")
            ->assertStatus(403);
        $this->actingAs($stranger)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/remove")
            ->assertStatus(403);

        $this->assertFalse($meeting->fresh()->is_locked);
        $this->assertSame(MeetingParticipant::STATUS_JOINED, $participant->fresh()->status);
    }

    public function test_a_participant_belonging_to_another_meeting_cannot_be_targeted(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $otherMeeting = Meeting::factory()->live()->create();
        $foreignParticipant = MeetingParticipant::factory()->for($otherMeeting, 'meeting')->create([
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$foreignParticipant->id}/mute")
            ->assertStatus(404);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$foreignParticipant->id}/spotlight")
            ->assertStatus(404);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$foreignParticipant->id}/promote")
            ->assertStatus(404);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$foreignParticipant->id}/remove")
            ->assertStatus(404);

        $this->assertSame(MeetingParticipant::STATUS_JOINED, $foreignParticipant->fresh()->status);
    }
}
