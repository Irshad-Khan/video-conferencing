<?php

namespace Tests\Feature\Meetings;

use App\Events\MeetingScheduled;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MeetingCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_user_can_create_a_meeting(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/meetings', [
            'title' => 'Sprint Planning',
            'description' => 'Weekly planning session',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Sprint Planning')
            ->assertJsonPath('data.status', Meeting::STATUS_SCHEDULED)
            ->assertJsonPath('data.has_password', false)
            ->assertJsonMissingPath('data.password');

        $response->assertJsonPath('data.meeting_uuid', fn ($uuid) => is_string($uuid) && strlen($uuid) === 36);
        $response->assertJsonPath('data.meeting_code', fn ($code) => (bool) preg_match('/^[A-Z0-9]{3}-[A-Z0-9]{3}-[A-Z0-9]{3}$/', $code));

        $this->assertDatabaseHas('meetings', [
            'title' => 'Sprint Planning',
            'host_user_id' => $user->id,
        ]);
    }

    public function test_a_meeting_can_be_created_with_a_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/meetings', [
            'title' => 'Confidential Review',
            'password' => 'secret123',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.has_password', true);

        $meeting = Meeting::where('title', 'Confidential Review')->firstOrFail();
        $this->assertNotSame('secret123', $meeting->getRawOriginal('password'));
        $this->assertTrue(Hash::check('secret123', $meeting->getRawOriginal('password')));
    }

    public function test_a_meeting_can_be_created_with_a_duration(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/meetings', [
            'title' => 'Standup',
            'duration_minutes' => 30,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.duration_minutes', 30);
        $this->assertDatabaseHas('meetings', ['title' => 'Standup', 'duration_minutes' => 30]);
    }

    public function test_duration_minutes_must_be_a_positive_integer_within_a_day(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/meetings', [
            'title' => 'Bad duration',
            'duration_minutes' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('duration_minutes');

        $this->actingAs($user)->postJson('/api/v1/meetings', [
            'title' => 'Bad duration',
            'duration_minutes' => 1441,
        ])->assertStatus(422)->assertJsonValidationErrors('duration_minutes');
    }

    public function test_creating_a_scheduled_meeting_dispatches_a_meeting_scheduled_event(): void
    {
        Event::fake([MeetingScheduled::class]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/meetings', [
            'title' => 'Future sync',
            'scheduled_at' => now()->addDay()->toISOString(),
        ])->assertStatus(201);

        Event::assertDispatched(MeetingScheduled::class, fn (MeetingScheduled $event) => $event->meeting->title === 'Future sync');
    }

    public function test_creating_an_instant_meeting_does_not_dispatch_a_meeting_scheduled_event(): void
    {
        Event::fake([MeetingScheduled::class]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/meetings', [
            'title' => 'Instant call',
        ])->assertStatus(201);

        Event::assertNotDispatched(MeetingScheduled::class);
    }

    public function test_a_guest_cannot_create_a_meeting(): void
    {
        $response = $this->postJson('/api/v1/meetings', ['title' => 'Nope']);

        $response->assertStatus(401);
        $this->assertDatabaseCount('meetings', 0);
    }

    public function test_creating_a_meeting_requires_a_title(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/meetings', []);

        $response->assertStatus(422)->assertJsonValidationErrors('title');
    }

    public function test_meeting_codes_remain_unique_even_under_a_forced_collision(): void
    {
        Meeting::factory()->create(['meeting_code' => 'AAA-AAA-AAA']);

        // Force the random generator to first reproduce the taken code, then a fresh one,
        // proving the retry loop in Meeting::generateUniqueCode() actually re-rolls on collision.
        // Tested against the model method directly (not over HTTP) so unrelated framework
        // internals (session id, CSRF token, etc.) that also call Str::random() can't
        // consume entries from the forced sequence and make this test flaky.
        Str::createRandomStringsUsingSequence([
            'AAA', 'AAA', 'AAA',
            'BBB', 'CCC', 'DDD',
        ]);

        try {
            $code = Meeting::generateUniqueCode();
        } finally {
            Str::createRandomStringsNormally();
        }

        $this->assertSame('BBB-CCC-DDD', $code);
        $this->assertSame(1, Meeting::where('meeting_code', 'AAA-AAA-AAA')->count());
    }
}
