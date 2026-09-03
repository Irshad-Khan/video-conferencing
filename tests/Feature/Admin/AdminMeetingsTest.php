<?php

namespace Tests\Feature\Admin;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMeetingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_list_every_meeting_across_every_host(): void
    {
        $admin = User::factory()->admin()->create();
        $hostA = User::factory()->create();
        $hostB = User::factory()->create();
        Meeting::factory()->for($hostA, 'host')->create(['title' => 'Host A Meeting']);
        Meeting::factory()->live()->for($hostB, 'host')->create(['title' => 'Host B Live Meeting']);

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/meetings')->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Host A Meeting'));
        $this->assertTrue($titles->contains('Host B Live Meeting'));
    }

    public function test_an_admin_can_view_any_meetings_participant_history(): void
    {
        $admin = User::factory()->admin()->create();
        $host = User::factory()->create();
        $meeting = Meeting::factory()->ended()->for($host, 'host')->create();
        MeetingParticipant::factory()->for($meeting)->create([
            'user_id' => null,
            'guest_name' => 'Ada Lovelace',
            'status' => MeetingParticipant::STATUS_LEFT,
            'joined_at' => now()->subHour(),
            'left_at' => now()->subMinutes(30),
            'duration_seconds' => 1800,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/admin/meetings/{$meeting->meeting_uuid}/participants")
            ->assertStatus(200);

        $response->assertJsonFragment(['name' => 'Ada Lovelace', 'duration_seconds' => 1800]);
    }

    public function test_an_admin_can_delete_any_hosts_meeting(): void
    {
        $admin = User::factory()->admin()->create();
        $host = User::factory()->create();
        $meeting = Meeting::factory()->ended()->for($host, 'host')->create();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/meetings/{$meeting->meeting_uuid}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
    }

    public function test_an_admin_cannot_delete_a_live_meeting(): void
    {
        $admin = User::factory()->admin()->create();
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/meetings/{$meeting->meeting_uuid}")
            ->assertStatus(409);

        $this->assertDatabaseHas('meetings', ['id' => $meeting->id]);
    }
}
