<?php

namespace Tests\Feature\Meetings;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MeetingBroadcastAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // The test suite defaults to the "null" broadcaster (see phpunit.xml), which
        // skips channel authorization entirely. These tests exercise that logic, so
        // they need the real Pusher-protocol broadcaster Reverb uses. This has to be
        // set before parent::setUp() boots the app — routes/channels.php registers
        // its Broadcast::channel() callbacks against whatever driver is default at
        // boot time, and a later config() call would target a different (fresh)
        // driver instance than the one auth requests actually resolve.
        putenv('BROADCAST_CONNECTION=reverb');
        $_ENV['BROADCAST_CONNECTION'] = 'reverb';
        $_SERVER['BROADCAST_CONNECTION'] = 'reverb';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('BROADCAST_CONNECTION=null');
        $_ENV['BROADCAST_CONNECTION'] = 'null';
        $_SERVER['BROADCAST_CONNECTION'] = 'null';

        parent::tearDown();
    }

    private function authRequest(): array
    {
        return [
            'socket_id' => '1234.5678',
        ];
    }

    public function test_an_active_participant_can_authorize_the_meeting_channel(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create();
        MeetingParticipant::factory()->for($meeting)->for($host, 'user')->create([
            'role' => MeetingParticipant::ROLE_HOST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $response = $this->actingAs($host)->postJson('/broadcasting/auth', [
            ...$this->authRequest(),
            'channel_name' => "private-meeting.{$meeting->meeting_uuid}",
        ]);

        $response->assertStatus(200);
    }

    public function test_a_user_who_never_joined_the_meeting_cannot_authorize_the_channel(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create();
        $stranger = User::factory()->create();

        $response = $this->actingAs($stranger)->postJson('/broadcasting/auth', [
            ...$this->authRequest(),
            'channel_name' => "private-meeting.{$meeting->meeting_uuid}",
        ]);

        $response->assertStatus(403);
    }

    public function test_a_waiting_room_participant_cannot_authorize_the_channel(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create(['waiting_room_enabled' => true]);
        $participant = User::factory()->create();
        MeetingParticipant::factory()->for($meeting)->for($participant, 'user')->create([
            'status' => MeetingParticipant::STATUS_WAITING,
        ]);

        $response = $this->actingAs($participant)->postJson('/broadcasting/auth', [
            ...$this->authRequest(),
            'channel_name' => "private-meeting.{$meeting->meeting_uuid}",
        ]);

        $response->assertStatus(403);
    }

    public function test_a_guest_with_a_valid_token_can_authorize_the_channel(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create();
        $rawToken = Str::random(64);
        MeetingParticipant::factory()->for($meeting)->create([
            'user_id' => null,
            'guest_name' => 'Guesty',
            'role' => MeetingParticipant::ROLE_GUEST,
            'status' => MeetingParticipant::STATUS_JOINED,
            'guest_token' => hash('sha256', $rawToken),
        ]);

        $response = $this->postJson('/broadcasting/auth', [
            ...$this->authRequest(),
            'channel_name' => "private-meeting.{$meeting->meeting_uuid}",
        ], ['X-Guest-Token' => $rawToken]);

        $response->assertStatus(200);
    }

    public function test_a_guest_with_an_invalid_token_cannot_authorize_the_channel(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create();

        $response = $this->postJson('/broadcasting/auth', [
            ...$this->authRequest(),
            'channel_name' => "private-meeting.{$meeting->meeting_uuid}",
        ], ['X-Guest-Token' => 'not-a-real-token']);

        $response->assertStatus(403);
    }

    public function test_an_unauthenticated_request_with_no_token_cannot_authorize_the_channel(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create();

        $response = $this->postJson('/broadcasting/auth', [
            ...$this->authRequest(),
            'channel_name' => "private-meeting.{$meeting->meeting_uuid}",
        ]);

        $response->assertStatus(403);
    }

    public function test_a_left_guest_token_cannot_reauthorize_the_channel(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create();
        $rawToken = Str::random(64);
        MeetingParticipant::factory()->for($meeting)->create([
            'user_id' => null,
            'guest_name' => 'Guesty',
            'role' => MeetingParticipant::ROLE_GUEST,
            'status' => MeetingParticipant::STATUS_LEFT,
            'guest_token' => hash('sha256', $rawToken),
        ]);

        $response = $this->postJson('/broadcasting/auth', [
            ...$this->authRequest(),
            'channel_name' => "private-meeting.{$meeting->meeting_uuid}",
        ], ['X-Guest-Token' => $rawToken]);

        $response->assertStatus(403);
    }

    public function test_a_removed_participant_cannot_reauthorize_the_channel(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->for($host, 'host')->create();
        $participant = User::factory()->create();
        MeetingParticipant::factory()->for($meeting)->for($participant, 'user')->create([
            'status' => MeetingParticipant::STATUS_REMOVED,
        ]);

        $response = $this->actingAs($participant)->postJson('/broadcasting/auth', [
            ...$this->authRequest(),
            'channel_name' => "private-meeting.{$meeting->meeting_uuid}",
        ]);

        $response->assertStatus(403);
    }

    public function test_an_unknown_meeting_uuid_cannot_be_authorized(): void
    {
        $host = User::factory()->create();
        Meeting::factory()->for($host, 'host')->create();

        $response = $this->actingAs($host)->postJson('/broadcasting/auth', [
            ...$this->authRequest(),
            'channel_name' => 'private-meeting.does-not-exist',
        ]);

        $response->assertStatus(403);
    }
}
