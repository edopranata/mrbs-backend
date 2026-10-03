<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_receive_token(): void
    {
        $user = User::factory()->create(['username' => 'budi']);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'budi',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'username', 'email', 'role']])
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['username' => 'budi']);

        $this->postJson('/api/auth/login', ['username' => 'budi', 'password' => 'salah'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->inactive()->create(['username' => 'budi']);

        $this->postJson('/api/auth/login', ['username' => 'budi', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }

    public function test_login_uses_username_case_insensitively(): void
    {
        User::factory()->create(['username' => 'budi', 'email' => 'budi@kantor.test']);

        $this->postJson('/api/auth/login', ['username' => '  BUDI ', 'password' => 'password'])->assertOk();

        // Email tidak lagi bisa dipakai untuk login
        $this->postJson('/api/auth/login', ['username' => 'budi@kantor.test', 'password' => 'password'])
            ->assertJsonValidationErrors('username');
        $this->postJson('/api/auth/login', ['email' => 'budi@kantor.test', 'password' => 'password'])
            ->assertJsonValidationErrors('username');
    }

    public function test_protected_routes_require_token(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_token(): void
    {
        User::factory()->create(['username' => 'budi']);
        $token = $this->postJson('/api/auth/login', ['username' => 'budi', 'password' => 'password'])
            ->json('token');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_deactivated_user_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('spa')->plainTextToken;
        $user->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/auth/me')->assertForbidden();
    }
}
