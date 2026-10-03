<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateSystemAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_system_admin_interactively(): void
    {
        $this->artisan('mrbs:create-sysadmin')
            ->expectsQuestion('Username (untuk login)', 'Admin.Pusat')
            ->expectsQuestion('Nama lengkap', 'Admin Pusat')
            ->expectsQuestion('Email', 'admin.pusat@kantor.test')
            ->expectsQuestion('Password (min. 8 karakter)', 'rahasia-kuat-123')
            ->expectsQuestion('Ulangi password', 'rahasia-kuat-123')
            ->expectsOutputToContain('berhasil dibuat')
            ->assertSuccessful();

        $user = User::where('username', 'admin.pusat')->firstOrFail();
        $this->assertTrue($user->isSystemAdmin());
        $this->assertTrue($user->is_active);

        $this->postJson('/api/auth/login', ['username' => 'admin.pusat', 'password' => 'rahasia-kuat-123'])->assertOk();
    }

    public function test_creates_system_admin_with_options(): void
    {
        $this->artisan('mrbs:create-sysadmin', [
            '--name' => 'Root', '--username' => 'root', '--email' => 'root@kantor.test', '--password' => 'rahasia-kuat-123',
        ])->assertSuccessful();

        $this->assertTrue(User::where('username', 'root')->first()->isSystemAdmin());
    }

    public function test_rejects_mismatched_confirmation_and_invalid_input(): void
    {
        $this->artisan('mrbs:create-sysadmin', ['--name' => 'X', '--username' => 'root', '--email' => 'root@kantor.test'])
            ->expectsQuestion('Password (min. 8 karakter)', 'rahasia-kuat-123')
            ->expectsQuestion('Ulangi password', 'beda-sekali-123')
            ->expectsOutputToContain('Konfirmasi password tidak cocok')
            ->assertFailed();

        $this->artisan('mrbs:create-sysadmin', [
            '--name' => 'X', '--username' => 'ada spasi', '--email' => 'bukan-email', '--password' => 'pendek',
        ])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_username_requires_force_and_is_promoted(): void
    {
        $user = User::factory()->create(['username' => 'budi', 'email' => 'budi@kantor.test']);
        $user->createToken('lama');
        $options = ['--username' => 'budi', '--name' => 'Budi', '--email' => 'budi@kantor.test', '--password' => 'password-baru-123'];

        $this->artisan('mrbs:create-sysadmin', $options)->expectsOutputToContain('--force')->assertFailed();
        $this->assertFalse($user->fresh()->isSystemAdmin());

        $this->artisan('mrbs:create-sysadmin', [...$options, '--force' => true])->assertSuccessful();

        $this->assertTrue($user->fresh()->isSystemAdmin());
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/auth/login', ['username' => 'budi', 'password' => 'password-baru-123'])->assertOk();
    }

    public function test_default_accounts_are_not_seeded_in_production(): void
    {
        // Seeder dipanggil langsung: `db:seed` di production meminta konfirmasi interaktif.
        $this->app->detectEnvironment(fn () => 'production');
        $this->app->make(UserSeeder::class)->run();
        $this->assertDatabaseCount('users', 0);

        $this->app->detectEnvironment(fn () => 'local');
        $this->app->make(UserSeeder::class)->run();
        $this->assertDatabaseCount('users', 3);
    }
}
