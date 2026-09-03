<?php

namespace Tests\Feature\Admin;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_list_users_with_their_meeting_count(): void
    {
        $admin = User::factory()->admin()->create();
        $host = User::factory()->create();
        Meeting::factory()->count(3)->for($host, 'host')->create();

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/users')->assertStatus(200);

        $response->assertJsonFragment(['id' => $host->id, 'meetings_count' => 3]);
    }

    public function test_an_admin_can_disable_and_enable_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$target->id}/disable")
            ->assertStatus(200)
            ->assertJsonPath('data.is_disabled', true);

        $this->assertTrue($target->fresh()->is_disabled);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$target->id}/enable")
            ->assertStatus(200)
            ->assertJsonPath('data.is_disabled', false);

        $this->assertFalse($target->fresh()->is_disabled);
    }

    public function test_an_admin_cannot_disable_their_own_account(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$admin->id}/disable")
            ->assertStatus(409);

        $this->assertFalse($admin->fresh()->is_disabled);
    }

    public function test_a_disabled_users_existing_session_is_rejected_on_the_next_request(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        // Confirms the target's session is valid before the admin disables them below.
        $this->actingAs($target)->getJson('/api/v1/profile')->assertStatus(200);

        $this->actingAs($admin)->postJson("/api/v1/admin/users/{$target->id}/disable")->assertStatus(200);

        // actingAs() sets whatever model instance it's given directly on the guard rather
        // than re-querying the database, so a stale $target object would still read as
        // enabled here — ->fresh() reflects the disable that just happened above.
        $this->actingAs($target->fresh())->getJson('/api/v1/profile')->assertStatus(403);
    }
}
