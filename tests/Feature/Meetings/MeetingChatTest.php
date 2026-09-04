<?php

namespace Tests\Feature\Meetings;

use App\Events\ChatMessageEvent;
use App\Models\Meeting;
use App\Models\MeetingMessage;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MeetingChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_joined_participant_can_send_and_receive_messages(): void
    {
        Event::fake([ChatMessageEvent::class]);

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        MeetingParticipant::factory()->for($meeting)->for($host, 'user')->create([
            'role' => MeetingParticipant::ROLE_HOST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $response = $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", ['message' => 'Hello everyone'])
            ->assertStatus(201)
            ->assertJsonPath('data.message', 'Hello everyone')
            ->assertJsonPath('data.sender_name', $host->name)
            ->assertJsonPath('data.sender_role', MeetingParticipant::ROLE_HOST);

        $this->assertDatabaseHas('meeting_messages', [
            'id' => $response->json('data.id'),
            'meeting_id' => $meeting->id,
            'user_id' => $host->id,
            'message' => 'Hello everyone',
        ]);

        $this->actingAs($host)
            ->getJson("/api/v1/meetings/{$meeting->meeting_code}/messages")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'Hello everyone');

        Event::assertDispatched(ChatMessageEvent::class, fn (ChatMessageEvent $event) => $event->message->message === 'Hello everyone');
    }

    public function test_a_guest_can_send_and_receive_messages_using_their_guest_token(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();

        $joinResponse = $this->postJson("/api/v1/meetings/{$meeting->meeting_code}/join", ['guest_name' => 'Gary Guest'])
            ->assertStatus(200);
        $guestToken = $joinResponse->json('data.guest_token');

        $this->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", [
            'message' => 'Hi from a guest',
            'guest_token' => $guestToken,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.sender_name', 'Gary Guest')
            ->assertJsonPath('data.sender_role', MeetingParticipant::ROLE_GUEST);

        $this->getJson("/api/v1/meetings/{$meeting->meeting_code}/messages?guest_token={$guestToken}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sender_name', 'Gary Guest');
    }

    public function test_messages_are_returned_in_chronological_order(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        MeetingParticipant::factory()->for($meeting)->for($host, 'user')->create([
            'role' => MeetingParticipant::ROLE_HOST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($host)->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", ['message' => 'first'])->assertStatus(201);
        $this->actingAs($host)->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", ['message' => 'second'])->assertStatus(201);
        $this->actingAs($host)->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", ['message' => 'third'])->assertStatus(201);

        $this->actingAs($host)
            ->getJson("/api/v1/meetings/{$meeting->meeting_code}/messages")
            ->assertStatus(200)
            ->assertJsonPath('data.0.message', 'first')
            ->assertJsonPath('data.1.message', 'second')
            ->assertJsonPath('data.2.message', 'third');
    }

    public function test_an_empty_message_is_rejected(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        MeetingParticipant::factory()->for($meeting)->for($host, 'user')->create([
            'role' => MeetingParticipant::ROLE_HOST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", ['message' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", ['message' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');

        $this->assertDatabaseCount('meeting_messages', 0);
    }

    public function test_a_message_over_the_length_limit_is_rejected(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        MeetingParticipant::factory()->for($meeting)->for($host, 'user')->create([
            'role' => MeetingParticipant::ROLE_HOST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", ['message' => str_repeat('a', 2001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_message_content_round_trips_unescaped_since_rendering_safety_is_the_frontends_job(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        MeetingParticipant::factory()->for($meeting)->for($host, 'user')->create([
            'role' => MeetingParticipant::ROLE_HOST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $payload = '<script>alert(1)</script>';

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", ['message' => $payload])
            ->assertStatus(201)
            ->assertJsonPath('data.message', $payload);

        $this->assertDatabaseHas('meeting_messages', ['message' => $payload]);
    }

    public function test_an_unauthenticated_non_participant_cannot_send_or_read_messages(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();

        $this->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", ['message' => 'hi'])
            ->assertStatus(403);

        $this->getJson("/api/v1/meetings/{$meeting->meeting_code}/messages")
            ->assertStatus(403);

        $this->assertDatabaseCount('meeting_messages', 0);
    }

    public function test_a_signed_in_user_who_never_joined_the_meeting_cannot_send_or_read_messages(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", ['message' => 'hi'])
            ->assertStatus(403);

        $this->actingAs($stranger)
            ->getJson("/api/v1/meetings/{$meeting->meeting_code}/messages")
            ->assertStatus(403);
    }

    public function test_a_participant_who_has_left_cannot_send_messages(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $user = User::factory()->create();
        MeetingParticipant::factory()->for($meeting)->for($user, 'user')->create([
            'status' => MeetingParticipant::STATUS_LEFT,
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", ['message' => 'hi'])
            ->assertStatus(403);
    }

    public function test_a_removed_participant_cannot_send_messages(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $user = User::factory()->create();
        MeetingParticipant::factory()->for($meeting)->for($user, 'user')->create([
            'status' => MeetingParticipant::STATUS_REMOVED,
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", ['message' => 'hi'])
            ->assertStatus(403);
    }

    public function test_a_guest_with_an_invalid_token_cannot_send_or_read_messages(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();

        $this->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", [
            'message' => 'hi',
            'guest_token' => 'not-a-real-token',
        ])->assertStatus(403);

        $this->getJson("/api/v1/meetings/{$meeting->meeting_code}/messages?guest_token=not-a-real-token")
            ->assertStatus(403);
    }

    public function test_a_participant_can_send_a_message_with_an_image_attachment(): void
    {
        Storage::fake('local');

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        MeetingParticipant::factory()->for($meeting)->for($host, 'user')->create([
            'role' => MeetingParticipant::ROLE_HOST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $image = UploadedFile::fake()->create('screenshot.png', 50, 'image/png');

        $response = $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", [
                'message' => 'check this out',
                'attachment' => $image,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.attachment.name', 'screenshot.png')
            ->assertJsonPath('data.attachment.is_image', true);

        $storedPath = MeetingMessage::first()->attachment_path;
        Storage::disk('local')->assertExists($storedPath);
        $this->assertNotSame('screenshot.png', basename($storedPath));
    }

    public function test_an_attachment_alone_without_message_text_is_allowed(): void
    {
        Storage::fake('local');

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        MeetingParticipant::factory()->for($meeting)->for($host, 'user')->create([
            'role' => MeetingParticipant::ROLE_HOST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", [
                'attachment' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.message', null)
            ->assertJsonPath('data.attachment.is_image', false);
    }

    public function test_neither_message_nor_attachment_is_rejected(): void
    {
        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        MeetingParticipant::factory()->for($meeting)->for($host, 'user')->create([
            'role' => MeetingParticipant::ROLE_HOST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_an_attachment_over_the_size_limit_is_rejected(): void
    {
        Storage::fake('local');

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        MeetingParticipant::factory()->for($meeting)->for($host, 'user')->create([
            'role' => MeetingParticipant::ROLE_HOST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", [
                'attachment' => UploadedFile::fake()->create('huge.pdf', 6000, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachment');

        $this->assertDatabaseCount('meeting_messages', 0);
    }

    public function test_a_disallowed_file_type_is_rejected(): void
    {
        Storage::fake('local');

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        MeetingParticipant::factory()->for($meeting)->for($host, 'user')->create([
            'role' => MeetingParticipant::ROLE_HOST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", [
                'attachment' => UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachment');

        $this->assertDatabaseCount('meeting_messages', 0);
    }

    public function test_a_participant_can_download_an_attachment(): void
    {
        Storage::fake('local');

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        MeetingParticipant::factory()->for($meeting)->for($host, 'user')->create([
            'role' => MeetingParticipant::ROLE_HOST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $upload = $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", [
                'attachment' => UploadedFile::fake()->create('pic.jpg', 50, 'image/jpeg'),
            ])
            ->assertStatus(201);

        $messageId = $upload->json('data.id');

        $this->actingAs($host)
            ->get("/api/v1/meetings/{$meeting->meeting_code}/messages/{$messageId}/attachment")
            ->assertStatus(200)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_guest_can_download_an_attachment_via_their_guest_token(): void
    {
        Storage::fake('local');

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();

        $joinResponse = $this->postJson("/api/v1/meetings/{$meeting->meeting_code}/join", ['guest_name' => 'Gary Guest'])
            ->assertStatus(200);
        $guestToken = $joinResponse->json('data.guest_token');

        $upload = $this->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", [
            'attachment' => UploadedFile::fake()->create('pic.jpg', 50, 'image/jpeg'),
            'guest_token' => $guestToken,
        ])->assertStatus(201);

        $messageId = $upload->json('data.id');

        $this->get("/api/v1/meetings/{$meeting->meeting_code}/messages/{$messageId}/attachment?guest_token={$guestToken}")
            ->assertStatus(200);
    }

    public function test_a_non_participant_cannot_download_an_attachment(): void
    {
        Storage::fake('local');

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        MeetingParticipant::factory()->for($meeting)->for($host, 'user')->create([
            'role' => MeetingParticipant::ROLE_HOST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $upload = $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", [
                'attachment' => UploadedFile::fake()->create('pic.jpg', 50, 'image/jpeg'),
            ])
            ->assertStatus(201);

        $messageId = $upload->json('data.id');

        // actingAs($host) above stays in effect for subsequent calls within this same
        // test method (a Sanctum RequestGuard quirk documented elsewhere in this
        // project) — forget it so this really is an anonymous guest request.
        $this->app['auth']->forgetGuards();
        $this->get("/api/v1/meetings/{$meeting->meeting_code}/messages/{$messageId}/attachment")
            ->assertStatus(403);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)
            ->get("/api/v1/meetings/{$meeting->meeting_code}/messages/{$messageId}/attachment")
            ->assertStatus(403);
    }

    public function test_an_attachment_belonging_to_another_meeting_cannot_be_targeted(): void
    {
        Storage::fake('local');

        $host = User::factory()->create();
        $meeting = Meeting::factory()->live()->for($host, 'host')->create();
        $otherMeeting = Meeting::factory()->live()->create();
        MeetingParticipant::factory()->for($meeting)->for($host, 'user')->create([
            'role' => MeetingParticipant::ROLE_HOST,
            'status' => MeetingParticipant::STATUS_JOINED,
        ]);

        $upload = $this->actingAs($host)
            ->postJson("/api/v1/meetings/{$meeting->meeting_code}/messages", [
                'attachment' => UploadedFile::fake()->create('pic.jpg', 50, 'image/jpeg'),
            ])
            ->assertStatus(201);

        $messageId = $upload->json('data.id');

        $this->actingAs($host)
            ->get("/api/v1/meetings/{$otherMeeting->meeting_code}/messages/{$messageId}/attachment")
            ->assertStatus(404);
    }

    public function test_an_unknown_meeting_code_returns_404(): void
    {
        $host = User::factory()->create();

        $this->actingAs($host)
            ->postJson('/api/v1/meetings/NOPE-NOPE-NOPE/messages', ['message' => 'hi'])
            ->assertStatus(404);

        $this->actingAs($host)
            ->getJson('/api/v1/meetings/NOPE-NOPE-NOPE/messages')
            ->assertStatus(404);
    }
}
