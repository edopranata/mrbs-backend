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
        $user = User::factory()->create(['email' => 'budi@kantor.test']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'budi@kantor.test',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']])
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'budi@kantor.test']);

        $this->postJson('/api/auth/login', ['email' => 'budi@kantor.test', 'password' => 'salah'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->inactive()->create(['email' => 'budi@kantor.test']);

        $this->postJson('/api/auth/login', ['email' => 'budi@kantor.test', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_protected_routes_require_token(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_token(): void
    {
        User::factory()->create(['email' => 'budi@kantor.test']);
        $token = $this->postJson('/api/auth/login', ['email' => 'budi@kantor.test', 'password' => 'password'])
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
