<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;

class MeetingPolicy
{
    public function view(User $user, Meeting $meeting): bool
    {
        return $user->id === $meeting->host_user_id;
    }

    public function update(User $user, Meeting $meeting): bool
    {
        return $user->id === $meeting->host_user_id;
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        return $user->id === $meeting->host_user_id;
    }

    public function manageLifecycle(User $user, Meeting $meeting): bool
    {
        return $user->id === $meeting->host_user_id;
    }

    /**
     * Appointing/revoking a co-host is host-only — unlike manageSettings/
     * manageParticipants below, this does not extend to co-hosts themselves
     * (matching Zoom: a co-host cannot appoint another co-host).
     */
    public function manageCoHosts(User $user, Meeting $meeting): bool
    {
        return $user->id === $meeting->host_user_id;
    }

    public function manageSettings(User $user, Meeting $meeting): bool
    {
        return $this->isHostOrCoHost($user, $meeting);
    }

    public function manageParticipants(User $user, Meeting $meeting): bool
    {
        return $this->isHostOrCoHost($user, $meeting);
    }

    private function isHostOrCoHost(User $user, Meeting $meeting): bool
    {
        if ($user->id === $meeting->host_user_id) {
            return true;
        }

        return $meeting->participants()
            ->where('user_id', $user->id)
            ->where('role', MeetingParticipant::ROLE_CO_HOST)
            ->exists();
    }
}
