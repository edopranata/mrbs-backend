<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_cannot_access_admin_endpoints(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();

        $this->actingAs($user)->getJson('/api/users')->assertForbidden();
        $this->actingAs($user)->postJson('/api/rooms', [])->assertForbidden();
        $this->actingAs($user)->putJson("/api/rooms/{$room->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/rooms/{$room->id}")->assertForbidden();
    }

    public function test_regular_user_only_sees_active_rooms(): void
    {
        Room::factory()->create();
        Room::factory()->inactive()->create();

        $this->actingAs(User::factory()->create())->getJson('/api/rooms')->assertJsonCount(1, 'data');
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/rooms')->assertJsonCount(2, 'data');
    }

    public function test_admin_can_manage_rooms(): void
    {
        $admin = User::factory()->admin()->create();

        $id = $this->actingAs($admin)->postJson('/api/rooms', [
            'code' => 'R8A', 'name' => 'Ruang Rapat 8A', 'floor' => 8, 'capacity' => 6,
            'facilities' => ['AC'],
        ])->assertCreated()->json('data.id');

        $this->actingAs($admin)->putJson("/api/rooms/{$id}", ['capacity' => 10, 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.capacity', 10)
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($admin)->deleteJson("/api/rooms/{$id}")->assertOk();
    }

    public function test_room_with_bookings_cannot_be_deleted(): void
    {
        $booking = Booking::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson("/api/rooms/{$booking->room_id}")
            ->assertUnprocessable();
    }

    public function test_admin_can_manage_users(): void
    {
        $admin = User::factory()->admin()->create();

        $id = $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Siti', 'email' => 'siti@kantor.test', 'password' => 'rahasia123', 'role' => 'user',
        ])->assertCreated()->json('data.id');

        $this->actingAs($admin)->putJson("/api/users/{$id}", ['role' => 'admin'])
            ->assertOk()->assertJsonPath('data.role', 'admin');

        $this->actingAs($admin)->deleteJson("/api/users/{$id}")->assertOk();
    }

    public function test_admin_cannot_demote_or_delete_self(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->putJson("/api/users/{$admin->id}", ['role' => 'user'])->assertUnprocessable();
        $this->actingAs($admin)->putJson("/api/users/{$admin->id}", ['is_active' => false])->assertUnprocessable();
        $this->actingAs($admin)->deleteJson("/api/users/{$admin->id}")->assertUnprocessable();
    }
}
