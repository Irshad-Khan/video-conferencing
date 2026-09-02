<?php

namespace App\Policies;

use App\Models\Meeting;
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

    public function manageSettings(User $user, Meeting $meeting): bool
    {
        return $user->id === $meeting->host_user_id;
    }

    public function manageParticipants(User $user, Meeting $meeting): bool
    {
        return $user->id === $meeting->host_user_id;
    }
}
