<?php

namespace Tests\Feature\Meetings;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_host_can_start_a_scheduled_meeting(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create();

        $response = $this->actingAs($host)->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/start");

        $response->assertStatus(200)->assertJsonPath('data.status', Meeting::STATUS_LIVE);

        $meeting->refresh();
        $this->assertSame(Meeting::STATUS_LIVE, $meeting->status);
        $this->assertNotNull($meeting->started_at);
    }

    public function test_a_live_meeting_cannot_be_started_again(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/start")
            ->assertStatus(409);
    }

    public function test_the_host_can_end_a_live_meeting(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();

        $response = $this->actingAs($host)->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/end");

        $response->assertStatus(200)->assertJsonPath('data.status', Meeting::STATUS_ENDED);

        $meeting->refresh();
        $this->assertSame(Meeting::STATUS_ENDED, $meeting->status);
        $this->assertNotNull($meeting->ended_at);
    }

    public function test_a_scheduled_meeting_cannot_be_ended(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create();

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/end")
            ->assertStatus(409);
    }

    public function test_the_host_can_cancel_a_scheduled_meeting(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create();

        $response = $this->actingAs($host)->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/cancel");

        $response->assertStatus(200)->assertJsonPath('data.status', Meeting::STATUS_CANCELLED);
    }

    public function test_a_live_meeting_cannot_be_cancelled(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/cancel")
            ->assertStatus(409);
    }

    public function test_an_ended_meeting_cannot_be_edited(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->ended()->for($host, 'host')->create(['title' => 'Original']);

        $this->actingAs($host)
            ->putJson("/api/v1/meetings/{$meeting->meeting_uuid}", ['title' => 'Changed'])
            ->assertStatus(409);

        $this->assertSame('Original', $meeting->fresh()->title);
    }

    public function test_a_live_meeting_cannot_be_deleted(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();

        $this->actingAs($host)
            ->deleteJson("/api/v1/meetings/{$meeting->meeting_uuid}")
            ->assertStatus(409);

        $this->assertModelExists($meeting);
    }

    public function test_an_ended_meeting_can_be_deleted(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->ended()->for($host, 'host')->create();

        $this->actingAs($host)
            ->deleteJson("/api/v1/meetings/{$meeting->meeting_uuid}")
            ->assertStatus(204);

        $this->assertModelMissing($meeting);
    }
}
