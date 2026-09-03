<?php

namespace Database\Factories;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Meeting>
 */
class MeetingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'host_user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'status' => Meeting::STATUS_SCHEDULED,
            'scheduled_at' => fake()->dateTimeBetween('now', '+1 week'),
            'duration_minutes' => fake()->optional()->randomElement([15, 30, 45, 60, 90, 120]),
        ];
    }

    public function live(): static
    {
        return $this->state(fn () => [
            'status' => Meeting::STATUS_LIVE,
            'started_at' => now(),
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn () => [
            'status' => Meeting::STATUS_ENDED,
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => Meeting::STATUS_CANCELLED,
        ]);
    }
}
