<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Level View Only: hanya melihat Dashboard, Jadwal Ruangan, dan Semua Booking.
 */
class ViewerRoleTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-05 10:15'));
        $this->viewer = User::factory()->viewer()->create();
        $this->room = Room::factory()->create(['capacity' => 10]);
    }

    public function test_viewer_can_see_dashboard_schedule_and_all_bookings(): void
    {
        Booking::factory()->create([
            'room_id' => $this->room->id, 'title' => 'Rapat Pagi', 'start_at' => '2026-10-05 10:00', 'end_at' => '2026-10-05 11:00',
        ]);
        $this->actingAs($this->viewer);

        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('stats.bookings_today', 1)->assertJsonMissingPath('admin');
        $this->getJson('/api/schedule?date=2026-10-05')->assertOk();
        $this->getJson('/api/bookings/today')->assertOk()->assertJsonPath('ongoing.0.title', 'Rapat Pagi');
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.role', 'viewer')->assertJsonPath('data.role_label', 'View Only');
    }

    public function test_regular_user_still_cannot_open_all_bookings(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/bookings/today')->assertForbidden();
    }

    public function test_viewer_cannot_create_change_or_cancel_bookings(): void
    {
        $booking = Booking::factory()->create([
            'room_id' => $this->room->id, 'user_id' => $this->viewer->id, 'start_at' => '2026-10-06 09:00', 'end_at' => '2026-10-06 10:00',
        ]);
        $this->actingAs($this->viewer);

        $this->postJson('/api/bookings', [
            'room_id' => $this->room->id,
            'title' => 'Rapat Tim',
            'start_at' => '2026-10-06 13:00',
            'end_at' => '2026-10-06 14:00',
            'participants' => 5,
        ])->assertForbidden()->assertJsonPath('message', 'Akun View Only hanya dapat melihat jadwal dan booking.');
        $this->assertSame(1, Booking::count());

        $this->getJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.can.update', false)
            ->assertJsonPath('data.can.cancel', false)
            ->assertJsonPath('data.can.delete', false);
        $this->putJson("/api/bookings/{$booking->id}", ['title' => 'Diubah'])->assertForbidden();
        $this->postJson("/api/bookings/{$booking->id}/cancel")->assertForbidden();
        $this->deleteJson("/api/bookings/{$booking->id}")->assertForbidden();
    }

    public function test_viewer_has_no_admin_access(): void
    {
        $this->actingAs($this->viewer);

        $this->getJson('/api/users')->assertForbidden();
        $this->postJson('/api/rooms', ['name' => 'Ruang Baru'])->assertForbidden();
    }

    public function test_admin_can_create_viewer_account(): void
    {
        $this->actingAs(User::factory()->admin()->create())->postJson('/api/users', [
            'name' => 'Resepsionis',
            'username' => 'resepsionis',
            'email' => 'resepsionis@example.com',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'role' => 'viewer',
        ])->assertCreated()->assertJsonPath('data.role_label', 'View Only');
    }
}
