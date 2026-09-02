<?php

namespace Tests\Feature\Meetings;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_cannot_view_update_or_delete_a_meeting(): void
    {
        $meeting = Meeting::factory()->create();

        $this->getJson("/api/v1/meetings/{$meeting->meeting_uuid}")->assertStatus(401);
        $this->putJson("/api/v1/meetings/{$meeting->meeting_uuid}", ['title' => 'x'])->assertStatus(401);
        $this->deleteJson("/api/v1/meetings/{$meeting->meeting_uuid}")->assertStatus(401);
        $this->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/start")->assertStatus(401);
    }

    public function test_a_non_host_cannot_view_a_meeting(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->getJson("/api/v1/meetings/{$meeting->meeting_uuid}")
            ->assertStatus(403);
    }

    public function test_a_non_host_cannot_update_a_meeting(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create(['title' => 'Original']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->putJson("/api/v1/meetings/{$meeting->meeting_uuid}", ['title' => 'Hijacked'])
            ->assertStatus(403);

        $this->assertSame('Original', $meeting->fresh()->title);
    }

    public function test_a_non_host_cannot_delete_a_meeting(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->deleteJson("/api/v1/meetings/{$meeting->meeting_uuid}")
            ->assertStatus(403);

        $this->assertModelExists($meeting);
    }

    public function test_a_non_host_cannot_cancel_start_or_end_a_meeting(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/cancel")->assertStatus(403);
        $this->actingAs($stranger)->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/start")->assertStatus(403);
        $this->actingAs($stranger)->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/end")->assertStatus(403);

        $this->assertSame(Meeting::STATUS_SCHEDULED, $meeting->fresh()->status);
    }

    public function test_the_host_can_view_update_and_delete_their_own_meeting(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create(['title' => 'Original']);

        $this->actingAs($host)->getJson("/api/v1/meetings/{$meeting->meeting_uuid}")->assertStatus(200);

        $this->actingAs($host)
            ->putJson("/api/v1/meetings/{$meeting->meeting_uuid}", ['title' => 'Updated'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Updated');

        $this->actingAs($host)
            ->deleteJson("/api/v1/meetings/{$meeting->meeting_uuid}")
            ->assertStatus(204);

        $this->assertModelMissing($meeting);
    }
}
