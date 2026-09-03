<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonMissingPath('data.password');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);

        $user = User::where('email', 'jane@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function test_registration_requires_a_unique_email(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertGuest();
    }

    public function test_registration_requires_matching_password_confirmation(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'not-the-same',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertGuest();
    }

    public function test_registration_requires_name_email_and_password(): void
    {
        $response = $this->postJson('/api/v1/auth/register', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_repeated_registration_attempts_are_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/register', [
                'name' => 'Jane Doe',
                'email' => "jane{$i}@example.com",
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])->assertStatus(201);
        }

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane-overflow@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(429);
        $this->assertDatabaseMissing('users', ['email' => 'jane-overflow@example.com']);
    }
}
