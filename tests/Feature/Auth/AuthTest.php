<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receive_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'SplitEasy User',
            'email' => 'user@spliteasy.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'token_type',
                    'token',
                    'user' => ['id', 'name', 'email'],
                ],
            ]);
    }

    public function test_user_can_login_fetch_profile_and_logout(): void
    {
        User::factory()->create([
            'email' => 'user@spliteasy.test',
            'password' => 'password123',
        ]);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@spliteasy.test',
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('data.token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'user@spliteasy.test');

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logout successful.');
    }

    public function test_invalid_login_returns_unauthorized_response(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@spliteasy.test',
            'password' => 'wrong-password',
        ]);

        $response
            ->assertUnauthorized()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Invalid login credentials.');
    }
}
