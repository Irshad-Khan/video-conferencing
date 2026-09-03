<?php

namespace Tests\Feature\Admin;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_reflect_current_counts(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(2)->create();
        User::factory()->disabled()->create();
        $host = User::factory()->create();
        Meeting::factory()->live()->for($host, 'host')->create();
        Meeting::factory()->ended()->for($host, 'host')->create();
        Meeting::factory()->for($host, 'host')->create();

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/stats')->assertStatus(200);

        $data = $response->json('data');
        $this->assertSame(5, $data['users']['total']); // admin + 2 + disabled + host
        $this->assertSame(1, $data['users']['admins']);
        $this->assertSame(1, $data['users']['disabled']);
        $this->assertSame(3, $data['meetings']['total']);
        $this->assertSame(1, $data['meetings']['live']);
        $this->assertSame(1, $data['meetings']['ended']);
        $this->assertSame(1, $data['meetings']['scheduled']);
    }
}
