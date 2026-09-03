<?php

namespace Tests\Feature\Meetings;

use App\Events\HostControlEvent;
use App\Events\WaitingRoomEvent;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MeetingWaitingRoomTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_participant_is_placed_in_the_waiting_room_when_enabled(): void
    {
        Event::fake([WaitingRoomEvent::class]);

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create(['waiting_room_enabled' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/join")
            ->assertStatus(200)
            ->assertJsonPath('data.participant.status', MeetingParticipant::STATUS_WAITING);

        Event::assertDispatched(WaitingRoomEvent::class, fn (WaitingRoomEvent $event) => $event->action === 'participant-waiting'
            && ! $event->forWaitingParticipant
            && $event->data['participant']['name'] === $user->name);
    }

    public function test_a_participant_joins_directly_when_the_waiting_room_is_disabled(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create(['waiting_room_enabled' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/join")
            ->assertStatus(200)
            ->assertJsonPath('data.participant.status', MeetingParticipant::STATUS_JOINED);
    }

    public function test_the_host_can_admit_a_waiting_participant(): void
    {
        Event::fake([WaitingRoomEvent::class]);

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $participant = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_WAITING,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/admit")
            ->assertStatus(200)
            ->assertJsonPath('data.status', MeetingParticipant::STATUS_ADMITTED);

        $participant->refresh();
        $this->assertSame(MeetingParticipant::STATUS_ADMITTED, $participant->status);
        $this->assertNotNull($participant->admitted_at);

        Event::assertDispatched(WaitingRoomEvent::class, function (WaitingRoomEvent $event) use ($participant) {
            return $event->action === 'admitted'
                && $event->forWaitingParticipant
                && $event->data['target'] === $participant->presenceId();
        });
    }

    public function test_the_host_can_reject_a_waiting_participant(): void
    {
        Event::fake([WaitingRoomEvent::class]);

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $participant = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_WAITING,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/reject")
            ->assertStatus(200)
            ->assertJsonPath('data.status', MeetingParticipant::STATUS_REJECTED);

        $participant->refresh();
        $this->assertSame(MeetingParticipant::STATUS_REJECTED, $participant->status);
        $this->assertNotNull($participant->left_at);
        $this->assertNull($participant->admitted_at);

        Event::assertDispatched(WaitingRoomEvent::class, function (WaitingRoomEvent $event) use ($participant) {
            return $event->action === 'rejected'
                && $event->forWaitingParticipant
                && $event->data['target'] === $participant->presenceId();
        });
    }

    public function test_a_rejected_participant_cannot_rejoin(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create(['waiting_room_enabled' => true]);
        $user = User::factory()->create();
        $participant = MeetingParticipant::factory()->for($meeting)->for($user, 'user')->create([
            'status' => MeetingParticipant::STATUS_WAITING,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/reject")
            ->assertStatus(200);

        $this->actingAs($user)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/join")
            ->assertStatus(403);
    }

    public function test_admitting_or_rejecting_a_participant_who_is_not_waiting_returns_409(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $participant = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/admit")
            ->assertStatus(409);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/reject")
            ->assertStatus(409);
    }

    public function test_a_reconnecting_participant_who_was_already_admitted_skips_the_waiting_room(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create(['waiting_room_enabled' => true]);
        $user = User::factory()->create();
        $participant = MeetingParticipant::factory()->for($meeting)->for($user, 'user')->create([
            'status' => MeetingParticipant::STATUS_ADMITTED,
            'admitted_at' => now()->subMinutes(5),
            'left_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/join")
            ->assertStatus(200)
            ->assertJsonPath('data.participant.status', MeetingParticipant::STATUS_JOINED);

        $participant->refresh();
        $this->assertSame(MeetingParticipant::STATUS_JOINED, $participant->status);
        $this->assertNotNull($participant->joined_at);
    }

    public function test_a_guest_reconnecting_with_their_guest_token_after_being_admitted_skips_the_waiting_room(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create(['waiting_room_enabled' => true]);

        $firstJoin = $this->postJson("/api/v1/meetings/{$meeting->meeting_code}/join", ['guest_name' => 'Waiting Guest'])
            ->assertStatus(200)
            ->assertJsonPath('data.participant.status', MeetingParticipant::STATUS_WAITING);
        $guestToken = $firstJoin->json('data.guest_token');
        $participantId = $firstJoin->json('data.participant.id');

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participantId}/admit")
            ->assertStatus(200);

        // actingAs() leaves the test client authenticated as $host for any further
        // request in this method — forget the resolved guards so the guest's
        // reconnect below is actually unauthenticated, matching how a real guest
        // browser would send it (auth:sanctum's RequestGuard has no logout()).
        $this->app['auth']->forgetGuards();

        $secondJoin = $this->postJson("/api/v1/meetings/{$meeting->meeting_code}/join", [
            'guest_name' => 'Waiting Guest',
            'guest_token' => $guestToken,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.participant.status', MeetingParticipant::STATUS_JOINED);

        $this->assertSame($participantId, $secondJoin->json('data.participant.id'));
        $this->assertDatabaseCount('meeting_participants', 1);
    }

    public function test_leaving_the_waiting_room_broadcasts_a_removal_to_the_host(): void
    {
        Event::fake([WaitingRoomEvent::class]);

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create(['waiting_room_enabled' => true]);
        $user = User::factory()->create();
        $participant = MeetingParticipant::factory()->for($meeting)->for($user, 'user')->create([
            'status' => MeetingParticipant::STATUS_WAITING,
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/leave")
            ->assertStatus(200);

        Event::assertDispatched(WaitingRoomEvent::class, function (WaitingRoomEvent $event) use ($participant) {
            return $event->action === 'participant-left-waiting'
                && ! $event->forWaitingParticipant
                && $event->data['id'] === $participant->id;
        });
    }

    public function test_the_host_can_list_currently_waiting_participants(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $waiting = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_WAITING,
        ]);
        MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($host)
            ->getJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/waiting")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $waiting->id);
    }

    public function test_the_host_can_toggle_the_waiting_room_live(): void
    {
        Event::fake([HostControlEvent::class]);

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create(['waiting_room_enabled' => false]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/waiting-room/enable")
            ->assertStatus(200)
            ->assertJsonPath('data.waiting_room_enabled', true);

        $this->assertTrue($meeting->fresh()->waiting_room_enabled);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/waiting-room/disable")
            ->assertStatus(200)
            ->assertJsonPath('data.waiting_room_enabled', false);

        $this->assertFalse($meeting->fresh()->waiting_room_enabled);

        Event::assertDispatched(HostControlEvent::class, fn (HostControlEvent $event) => $event->action === 'waiting-room-enabled');
        Event::assertDispatched(HostControlEvent::class, fn (HostControlEvent $event) => $event->action === 'waiting-room-disabled');
    }

    public function test_a_guest_cannot_call_any_waiting_room_endpoint(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $participant = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_WAITING,
        ]);

        $this->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/admit")->assertStatus(401);
        $this->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/reject")->assertStatus(401);
        $this->getJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/waiting")->assertStatus(401);
        $this->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/waiting-room/enable")->assertStatus(401);
    }

    public function test_a_non_host_participant_cannot_call_any_waiting_room_endpoint(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $stranger = User::factory()->create();
        $participant = MeetingParticipant::factory()->for($meeting)->create([
            'status' => MeetingParticipant::STATUS_WAITING,
        ]);

        $this->actingAs($stranger)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/admit")
            ->assertStatus(403);
        $this->actingAs($stranger)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$participant->id}/reject")
            ->assertStatus(403);
        $this->actingAs($stranger)
            ->getJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/waiting")
            ->assertStatus(403);
        $this->actingAs($stranger)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/waiting-room/enable")
            ->assertStatus(403);

        $this->assertSame(MeetingParticipant::STATUS_WAITING, $participant->fresh()->status);
    }

    public function test_a_waiting_participant_from_another_meeting_cannot_be_targeted(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $otherMeeting = Meeting::factory()->live()->create();
        $foreignParticipant = MeetingParticipant::factory()->for($otherMeeting, 'meeting')->create([
            'status' => MeetingParticipant::STATUS_WAITING,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$foreignParticipant->id}/admit")
            ->assertStatus(404);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_uuid}/participants/{$foreignParticipant->id}/reject")
            ->assertStatus(404);
    }
}
