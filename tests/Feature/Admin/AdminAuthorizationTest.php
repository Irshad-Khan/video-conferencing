<?php

namespace Tests\Feature\Admin;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_cannot_call_any_admin_endpoint(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->ended()->for($host, 'host')->create();
        $target = User::factory()->create();

        $this->getJson('/api/v1/admin/stats')->assertStatus(401);
        $this->getJson('/api/v1/admin/users')->assertStatus(401);
        $this->postJson("/api/v1/admin/users/{$target->id}/disable")->assertStatus(401);
        $this->postJson("/api/v1/admin/users/{$target->id}/enable")->assertStatus(401);
        $this->getJson('/api/v1/admin/meetings')->assertStatus(401);
        $this->getJson("/api/v1/admin/meetings/{$meeting->meeting_uuid}/participants")->assertStatus(401);
        $this->deleteJson("/api/v1/admin/meetings/{$meeting->meeting_uuid}")->assertStatus(401);
    }

    public function test_a_non_admin_user_cannot_call_any_admin_endpoint(): void
    {
        $user = User::factory()->create();
        $host = User::factory()->create();
        $meeting = Meeting::factory()->ended()->for($host, 'host')->create();
        $target = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/admin/stats')->assertStatus(403);
        $this->actingAs($user)->getJson('/api/v1/admin/users')->assertStatus(403);
        $this->actingAs($user)->postJson("/api/v1/admin/users/{$target->id}/disable")->assertStatus(403);
        $this->actingAs($user)->postJson("/api/v1/admin/users/{$target->id}/enable")->assertStatus(403);
        $this->actingAs($user)->getJson('/api/v1/admin/meetings')->assertStatus(403);
        $this->actingAs($user)->getJson("/api/v1/admin/meetings/{$meeting->meeting_uuid}/participants")->assertStatus(403);
        $this->actingAs($user)->deleteJson("/api/v1/admin/meetings/{$meeting->meeting_uuid}")->assertStatus(403);
    }

    public function test_an_admin_can_call_admin_endpoints(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/v1/admin/stats')->assertStatus(200);
        $this->actingAs($admin)->getJson('/api/v1/admin/users')->assertStatus(200);
        $this->actingAs($admin)->getJson('/api/v1/admin/meetings')->assertStatus(200);
    }
}
