<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SystemAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_admin_can_access_all_admin_features(): void
    {
        $sysadmin = User::factory()->systemAdmin()->create();
        $room = Room::factory()->create();

        $this->actingAs($sysadmin)->getJson('/api/users')->assertOk();
        $this->actingAs($sysadmin)->putJson("/api/rooms/{$room->id}", ['capacity' => 12])->assertOk();
        $this->actingAs($sysadmin)->getJson('/api/dashboard')->assertOk()->assertJsonStructure(['admin']);
        $this->actingAs($sysadmin)->getJson('/api/settings/manage')->assertOk();
    }

    public function test_only_system_admin_can_manage_settings(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)->getJson('/api/settings/manage')->assertForbidden();
            $this->actingAs($user)->putJson('/api/settings', ['app_name' => 'X'])->assertForbidden();
            $this->actingAs($user)->deleteJson('/api/settings')->assertForbidden();
        }
    }

    public function test_settings_are_public_for_login_page(): void
    {
        $this->getJson('/api/settings')->assertOk()->assertJsonStructure(['app_name', 'open_time', 'slot_minutes']);
    }

    public function test_updated_settings_apply_to_booking_rules(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 06:00'));
        $sysadmin = User::factory()->systemAdmin()->create();

        $this->actingAs($sysadmin)->putJson('/api/settings', [
            'app_name' => 'Booking Kantor',
            'open_time' => '08:00',
            'close_time' => '17:00',
        ])->assertOk()->assertJsonPath('values.open_time', '08:00')->assertJsonPath('overridden', ['app_name', 'open_time', 'close_time']);

        $this->getJson('/api/settings')->assertJsonPath('app_name', 'Booking Kantor')->assertJsonPath('close_time', '17:00');

        $room = Room::factory()->create();
        $this->actingAs(User::factory()->create())->postJson('/api/bookings', [
            'room_id' => $room->id, 'title' => 'Pagi', 'start_at' => '2026-10-06 07:30', 'end_at' => '2026-10-06 08:30',
        ])->assertJsonPath('errors.start_at.0', 'Ruang rapat hanya bisa dipesan antara pukul 08:00 - 17:00.');
    }

    public function test_settings_must_be_consistent(): void
    {
        $sysadmin = User::factory()->systemAdmin()->create();

        $this->actingAs($sysadmin)->putJson('/api/settings', ['open_time' => '18:00', 'close_time' => '08:00'])
            ->assertJsonValidationErrors('close_time');
        $this->actingAs($sysadmin)->putJson('/api/settings', ['slot_minutes' => 60, 'min_duration' => 30])
            ->assertJsonValidationErrors('min_duration');
        $this->actingAs($sysadmin)->putJson('/api/settings', ['min_duration' => 120, 'max_duration' => 60])
            ->assertJsonValidationErrors('max_duration');
        $this->actingAs($sysadmin)->putJson('/api/settings', ['slot_minutes' => 45])
            ->assertJsonValidationErrors('slot_minutes');
    }

    public function test_reset_restores_defaults(): void
    {
        $sysadmin = User::factory()->systemAdmin()->create();
        $default = config('mrbs.app_name');

        $this->actingAs($sysadmin)->putJson('/api/settings', ['app_name' => 'Lain'])->assertOk();
        $this->actingAs($sysadmin)->deleteJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('values.app_name', $default)
            ->assertJsonPath('overridden', []);
    }

    public function test_admin_cannot_create_or_manage_system_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $sysadmin = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'X', 'email' => 'x@kantor.test', 'password' => 'rahasia123', 'role' => 'system_admin',
        ])->assertForbidden();
        $this->actingAs($admin)->putJson("/api/users/{$user->id}", ['role' => 'system_admin'])->assertForbidden();
        $this->actingAs($admin)->putJson("/api/users/{$sysadmin->id}", ['name' => 'Ganti'])->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/users/{$sysadmin->id}")->assertForbidden();

        // Admin tetap bisa mengelola user & admin biasa
        $this->actingAs($admin)->putJson("/api/users/{$user->id}", ['role' => 'admin'])->assertOk();
    }

    public function test_system_admin_can_manage_system_admins(): void
    {
        $sysadmin = User::factory()->systemAdmin()->create();
        $other = User::factory()->create();

        $this->actingAs($sysadmin)->putJson("/api/users/{$other->id}", ['role' => 'system_admin'])
            ->assertOk()
            ->assertJsonPath('data.role_label', 'System Admin');
        $this->actingAs($sysadmin)->deleteJson("/api/users/{$other->id}")->assertOk();
    }
}
