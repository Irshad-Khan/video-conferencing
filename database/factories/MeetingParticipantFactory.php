<?php

namespace Database\Factories;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeetingParticipant>
 */
class MeetingParticipantFactory extends Factory
{
    protected $model = MeetingParticipant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meeting_id' => Meeting::factory(),
            'user_id' => User::factory(),
            'guest_name' => null,
            'role' => MeetingParticipant::ROLE_PARTICIPANT,
            'status' => MeetingParticipant::STATUS_JOINED,
            'joined_at' => now(),
            'left_at' => null,
        ];
    }
}
