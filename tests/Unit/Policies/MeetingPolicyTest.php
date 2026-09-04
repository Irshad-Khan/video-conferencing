<?php

namespace Tests\Unit\Policies;

use App\Models\Meeting;
use App\Models\User;
use App\Policies\MeetingPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Framework-free: no database, no HTTP kernel, no service container — just plain
 * (unsaved) model instances checked directly against the policy. `id`/`host_user_id`
 * aren't in either model's #[Fillable(...)] list (deliberately, since they're
 * internal/lifecycle fields — see BUG-007 in BUGS.md), so the constructor's
 * mass-assignment silently drops them; forceFill() is used here to set them anyway,
 * the same way the rest of the app sets non-fillable fields.
 */
class MeetingPolicyTest extends TestCase
{
    private MeetingPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new MeetingPolicy;
    }

    private function userWithId(int $id): User
    {
        return (new User)->forceFill(['id' => $id]);
    }

    private function meetingHostedBy(int $hostUserId): Meeting
    {
        return (new Meeting)->forceFill(['host_user_id' => $hostUserId]);
    }

    public function test_the_host_passes_every_ability(): void
    {
        $host = $this->userWithId(1);
        $meeting = $this->meetingHostedBy(1);

        $this->assertTrue($this->policy->view($host, $meeting));
        $this->assertTrue($this->policy->update($host, $meeting));
        $this->assertTrue($this->policy->delete($host, $meeting));
        $this->assertTrue($this->policy->manageLifecycle($host, $meeting));
        $this->assertTrue($this->policy->manageCoHosts($host, $meeting));
        // manageSettings/manageParticipants short-circuit true for the host before
        // ever touching the co-host DB lookup, so these two stay framework-free-safe.
        $this->assertTrue($this->policy->manageSettings($host, $meeting));
        $this->assertTrue($this->policy->manageParticipants($host, $meeting));
    }

    /**
     * Deliberately excludes manageSettings/manageParticipants: for a *non*-host, both
     * now fall through to a real "is this user a co-host of this meeting?" database
     * query (see isHostOrCoHost() in MeetingPolicy, added for Phase 22's co-host
     * feature) — that branch can't run in this framework-free test (no DB connection
     * resolver is bootstrapped here at all). It's covered instead by Feature tests
     * that exercise the real HTTP+DB path: MeetingControlsAuthorizationTest's
     * "a non host participant cannot call any host control endpoint" (stranger fails)
     * and MeetingControlsTest's "a co host can mute and remove participants..."
     * (co-host succeeds).
     */
    public function test_a_non_host_fails_every_db_free_ability(): void
    {
        $stranger = $this->userWithId(2);
        $meeting = $this->meetingHostedBy(1);

        $this->assertFalse($this->policy->view($stranger, $meeting));
        $this->assertFalse($this->policy->update($stranger, $meeting));
        $this->assertFalse($this->policy->delete($stranger, $meeting));
        $this->assertFalse($this->policy->manageLifecycle($stranger, $meeting));
        $this->assertFalse($this->policy->manageCoHosts($stranger, $meeting));
    }

    public function test_ability_checks_use_strict_identity_comparison(): void
    {
        // A guard against a loose `==` regression: host_user_id "1" (string) and
        // user id 1 (int) must not be treated as a match via type-juggling.
        $host = $this->userWithId(1);
        $meeting = (new Meeting)->forceFill(['host_user_id' => '1']);

        $this->assertFalse($this->policy->view($host, $meeting));
    }
}
