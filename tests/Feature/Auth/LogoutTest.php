<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertStatus(200);

        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(204);

        // Assert against the concrete 'web' guard (the one session logout targets).
        // The default guard becomes 'sanctum' for the remainder of this request's
        // lifecycle once auth:sanctum middleware runs, and Sanctum's RequestGuard
        // memoizes its resolved user per-instance — within a single test process
        // (unlike real, per-request production processes) that stale memoized
        // answer would otherwise make assertGuest() check the wrong guard.
        $this->assertGuest('web');
    }

    public function test_a_guest_cannot_logout(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401);
    }
}
