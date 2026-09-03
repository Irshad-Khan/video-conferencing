<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Firing enough requests to actually trip the general 120/min ceiling would make
 * this suite slow for little extra confidence — Laravel's own ThrottleRequests
 * middleware is already well-tested. These assert only that it's actually wired up
 * (via the rate-limit headers it adds to every response) on routes that had no
 * throttle at all before Phase 16 — the real regression risk being guarded against
 * is someone removing throttleApi() from bootstrap/app.php without noticing every
 * meeting/admin endpoint went back to being completely unthrottled.
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_sanctum_route_carries_the_general_rate_limit(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/meetings');

        $response->assertStatus(200);
        $response->assertHeader('X-RateLimit-Limit', '120');
    }

    public function test_the_public_lookup_endpoint_carries_its_own_tighter_rate_limit(): void
    {
        $meeting = Meeting::factory()->create();

        $response = $this->getJson("/api/v1/meetings/lookup/{$meeting->meeting_code}");

        $response->assertStatus(200);
        $response->assertHeader('X-RateLimit-Limit', '30');
    }

    public function test_the_admin_group_carries_the_general_rate_limit(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/stats');

        $response->assertStatus(200);
        $response->assertHeader('X-RateLimit-Limit', '120');
    }
}
