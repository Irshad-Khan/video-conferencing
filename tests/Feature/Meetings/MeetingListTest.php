<?php

namespace Tests\Feature\Meetings;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingListTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_only_the_authenticated_users_own_meetings(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $mine = Meeting::factory()->for($user, 'host')->create(['title' => 'Mine']);
        Meeting::factory()->for($other, 'host')->create(['title' => 'Not mine']);

        $response = $this->actingAs($user)->getJson('/api/v1/meetings');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_it_includes_meetings_across_every_status(): void
    {
        $user = User::factory()->create();

        Meeting::factory()->for($user, 'host')->create();
        Meeting::factory()->live()->for($user, 'host')->create();
        Meeting::factory()->ended()->for($user, 'host')->create();
        Meeting::factory()->cancelled()->for($user, 'host')->create();

        $response = $this->actingAs($user)->getJson('/api/v1/meetings');

        $response->assertStatus(200)->assertJsonCount(4, 'data');
    }

    public function test_a_guest_cannot_list_meetings(): void
    {
        $this->getJson('/api/v1/meetings')->assertStatus(401);
    }
}
