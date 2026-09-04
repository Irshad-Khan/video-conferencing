<?php

namespace Tests\Feature\Meetings;

use App\Events\HostControlEvent;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MeetingControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_host_can_lock_and_unlock_the_meeting(): void
    {
        Event::fake([HostControlEvent::class]);

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/lock")
            ->assertStatus(200)
            ->assertJsonPath('data.is_locked', true);

        $this->assertTrue($meeting->fresh()->is_locked);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/unlock")
            ->assertStatus(200)
            ->assertJsonPath('data.is_locked', false);

        $this->assertFalse($meeting->fresh()->is_locked);

        Event::assertDispatched(HostControlEvent::class, fn (HostControlEvent $event) => $event->action === 'lock');
        Event::assertDispatched(HostControlEvent::class, fn (HostControlEvent $event) => $event->action === 'unlock');
    }

    public function test_the_host_can_toggle_guest_access(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create(['allow_guests' => true]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/guests/disable")
            ->assertStatus(200)
            ->assertJsonPath('data.allow_guests', false);

        $this->assertFalse($meeting->fresh()->allow_guests);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/guests/enable")
            ->assertStatus(200)
            ->assertJsonPath('data.allow_guests', true);

        $this->assertTrue($meeting->fresh()->allow_guests);
    }

    public function test_the_host_can_mute_a_participant(): void
    {
        Event::fake([HostControlEvent::class]);

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $participant = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/mute")
            ->assertStatus(200)
            ->assertJsonPath('data.muted', true);

        Event::assertDispatched(HostControlEvent::class, function (HostControlEvent $event) use ($participant) {
            return $event->action === 'mute' && $event->data['target'] === $participant->presenceId();
        });
    }

    public function test_muting_a_participant_who_is_not_currently_joined_returns_409(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $participant = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_LEFT,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/mute")
            ->assertStatus(409);
    }

    public function test_the_host_can_promote_and_demote_a_co_host(): void
    {
        Event::fake([HostControlEvent::class]);

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $participant = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_JOINED,
            'role' => MeetingParticipant::ROLE_PARTICIPANT,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/promote")
            ->assertStatus(200)
            ->assertJsonPath('data.role', MeetingParticipant::ROLE_CO_HOST);

        $this->assertSame(MeetingParticipant::ROLE_CO_HOST, $participant->fresh()->role);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/demote")
            ->assertStatus(200)
            ->assertJsonPath('data.role', MeetingParticipant::ROLE_PARTICIPANT);

        $this->assertSame(MeetingParticipant::ROLE_PARTICIPANT, $participant->fresh()->role);

        Event::assertDispatched(HostControlEvent::class, fn (HostControlEvent $e) => $e->action === 'co-host-promoted');
        Event::assertDispatched(HostControlEvent::class, fn (HostControlEvent $e) => $e->action === 'co-host-demoted');
    }

    public function test_a_guest_cannot_be_promoted_to_co_host(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $guest = MeetingParticipant::factory()->for($meeting)->create([
            'user_id' => null,
            'guest_name' => 'Guest Grace',
            'role' => MeetingParticipant::ROLE_GUEST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$guest->id}/promote")
            ->assertStatus(422);

        $this->assertSame(MeetingParticipant::ROLE_GUEST, $guest->fresh()->role);
    }

    public function test_promoting_the_host_themselves_returns_409(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $hostParticipant = MeetingParticipant::factory()->for($meeting)->for($host)->create([
            'role' => MeetingParticipant::ROLE_HOST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$hostParticipant->id}/promote")
            ->assertStatus(409);
    }

    public function test_a_co_host_can_mute_and_remove_participants_but_cannot_promote_or_end_the_meeting(): void
    {
        Event::fake([HostControlEvent::class]);

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $coHostUser = User::factory()->create();
        MeetingParticipant::factory()->for($meeting)->for($coHostUser)->create([
            'role' => MeetingParticipant::ROLE_CO_HOST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);
        $target = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($coHostUser)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$target->id}/mute")
            ->assertStatus(200);

        $anotherTarget = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);
        $this->actingAs($coHostUser)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$anotherTarget->id}/promote")
            ->assertStatus(403);

        $this->actingAs($coHostUser)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/end")
            ->assertStatus(403);

        $this->assertSame(Meeting::STATUS_LIVE, $meeting->fresh()->status);
    }

    public function test_the_host_can_spotlight_a_participant(): void
    {
        Event::fake([HostControlEvent::class]);

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $participant = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/spotlight")
            ->assertStatus(200)
            ->assertJsonPath('data.spotlighted', true);

        Event::assertDispatched(HostControlEvent::class, function (HostControlEvent $event) use ($participant) {
            return $event->action === 'spotlight' && $event->data['target'] === $participant->presenceId();
        });
    }

    public function test_spotlighting_a_participant_who_is_not_currently_joined_returns_409(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $participant = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_LEFT,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/spotlight")
            ->assertStatus(409);
    }

    public function test_the_host_can_clear_the_spotlight(): void
    {
        Event::fake([HostControlEvent::class]);

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/spotlight/clear")
            ->assertStatus(200)
            ->assertJsonPath('data.spotlighted', false);

        Event::assertDispatched(HostControlEvent::class, fn (HostControlEvent $event) => $event->action === 'spotlight-cleared');
    }

    public function test_the_host_can_remove_a_participant(): void
    {
        Event::fake([HostControlEvent::class]);

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $participant = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/remove")
            ->assertStatus(200)
            ->assertJsonPath('data.status', MeetingParticipant::STATUS_REMOVED);

        $participant->refresh();
        $this->assertSame(MeetingParticipant::STATUS_REMOVED, $participant->status);
        $this->assertNotNull($participant->left_at);

        Event::assertDispatched(HostControlEvent::class, function (HostControlEvent $event) use ($participant) {
            return $event->action === 'remove' && $event->data['target'] === $participant->presenceId();
        });
    }

    public function test_a_removed_participant_cannot_rejoin_the_meeting(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $user = User::factory()->create();
        $participant = MeetingParticipant::factory()->for($meeting)->for($user, 'user')->create([
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($host)->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/remove")
            ->assertStatus(200);

        $this->actingAs($user)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/join")
            ->assertStatus(403);
    }

    public function test_removing_an_already_removed_participant_returns_409(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $participant = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_REMOVED,
            'left_at' => now(),
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/remove")
            ->assertStatus(409);
    }

    public function test_the_host_cannot_remove_themselves(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $hostParticipant = MeetingParticipant::factory()->for($meeting)->for($host, 'user')->create([
            'role' => MeetingParticipant::ROLE_HOST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$hostParticipant->id}/remove")
            ->assertStatus(409);

        $this->assertSame(MeetingParticipant::STATUS_JOINED, $hostParticipant->fresh()->status);
    }

    public function test_ending_a_meeting_broadcasts_a_host_control_event(): void
    {
        Event::fake([HostControlEvent::class]);

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/end")
            ->assertStatus(200);

        Event::assertDispatched(HostControlEvent::class, fn (HostControlEvent $event) => $event->action === 'end');
    }

    public function test_a_locked_meeting_rejects_a_new_guest_join(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create(['is_locked' => true]);

        $this->postJson("/api/v1/meetings/{$meeting->meeting_code}/join", ['guest_name' => 'Late Guest'])
            ->assertStatus(403);
    }

    public function test_a_locked_meeting_still_allows_the_host_to_join(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create(['is_locked' => true]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/join")
            ->assertStatus(200);
    }

    public function test_the_lookup_endpoint_reports_the_locked_status(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create(['is_locked' => true]);

        $this->getJson("/api/v1/meetings/lookup/{$meeting->meeting_code}")
            ->assertStatus(200)
            ->assertJsonPath('data.is_locked', true);
    }
}
