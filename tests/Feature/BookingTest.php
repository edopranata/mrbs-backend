<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Room $room;

    private string $tomorrow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 08:00'));
        $this->tomorrow = '2026-09-29';
        $this->user = User::factory()->create();
        $this->room = Room::factory()->create(['capacity' => 10]);
    }

    private function book(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->postJson('/api/bookings', [
            'room_id' => $this->room->id,
            'title' => 'Rapat Tim',
            'start_at' => "{$this->tomorrow} 09:00",
            'end_at' => "{$this->tomorrow} 10:00",
            'participants' => 5,
            ...$overrides,
        ]);
    }

    public function test_user_can_create_booking(): void
    {
        $this->book()
            ->assertCreated()
            ->assertJsonPath('data.start_time', '09:00')
            ->assertJsonPath('data.end_time', '10:00')
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.user.id', $this->user->id);
    }

    public function test_overlapping_booking_is_rejected(): void
    {
        $this->book()->assertCreated();

        $this->book(['start_at' => "{$this->tomorrow} 09:30", 'end_at' => "{$this->tomorrow} 10:30"])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_at');

        $this->book(['start_at' => "{$this->tomorrow} 08:00", 'end_at' => "{$this->tomorrow} 11:00"])
            ->assertUnprocessable();
    }

    public function test_back_to_back_bookings_are_allowed(): void
    {
        $this->book()->assertCreated();

        $this->book(['start_at' => "{$this->tomorrow} 10:00", 'end_at' => "{$this->tomorrow} 11:00"])
            ->assertCreated();
    }

    public function test_same_time_in_different_room_is_allowed(): void
    {
        $this->book()->assertCreated();

        $this->book(['room_id' => Room::factory()->create()->id])->assertCreated();
    }

    public function test_cancelled_booking_frees_the_slot(): void
    {
        Booking::factory()->cancelled()->create([
            'room_id' => $this->room->id,
            'start_at' => "{$this->tomorrow} 09:00",
            'end_at' => "{$this->tomorrow} 10:00",
        ]);

        $this->book()->assertCreated();
    }

    public function test_booking_rules_are_enforced(): void
    {
        // Masa lalu
        $this->book(['start_at' => '2026-09-27 09:00', 'end_at' => '2026-09-27 10:00'])
            ->assertJsonValidationErrors('start_at');
        // Di luar jam operasional (07:00 - 20:00)
        $this->book(['start_at' => "{$this->tomorrow} 19:30", 'end_at' => "{$this->tomorrow} 21:00"])
            ->assertJsonValidationErrors('start_at');
        // Selesai sebelum mulai
        $this->book(['start_at' => "{$this->tomorrow} 10:00", 'end_at' => "{$this->tomorrow} 09:00"])
            ->assertJsonValidationErrors('end_at');
        // Melebihi kapasitas
        $this->book(['participants' => 11])->assertJsonValidationErrors('participants');
        // Ruangan nonaktif
        $this->book(['room_id' => Room::factory()->inactive()->create()->id])
            ->assertJsonValidationErrors('room_id');
    }

    public function test_times_must_follow_slot_interval(): void
    {
        $this->book(['start_at' => "{$this->tomorrow} 09:15", 'end_at' => "{$this->tomorrow} 10:00"])
            ->assertJsonValidationErrors('start_at');
        $this->book(['start_at' => "{$this->tomorrow} 09:00", 'end_at' => "{$this->tomorrow} 09:45"])
            ->assertJsonValidationErrors('start_at');

        // Durasi minimal kini 30 menit
        $this->book(['start_at' => "{$this->tomorrow} 11:00", 'end_at' => "{$this->tomorrow} 11:30"])->assertCreated();
    }

    public function test_legacy_unaligned_booking_can_still_be_renamed(): void
    {
        $booking = Booking::factory()->create([
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'start_at' => "{$this->tomorrow} 13:15",
            'end_at' => "{$this->tomorrow} 14:15",
        ]);

        $this->actingAs($this->user)->putJson("/api/bookings/{$booking->id}", ['title' => 'Judul baru'])
            ->assertOk()
            ->assertJsonPath('data.start_time', '13:15');
    }

    public function test_user_can_update_own_booking_without_conflicting_with_itself(): void
    {
        $id = $this->book()->json('data.id');

        $this->actingAs($this->user)->putJson("/api/bookings/{$id}", [
            'end_at' => "{$this->tomorrow} 10:30",
            'title' => 'Rapat Tim (diperpanjang)',
        ])->assertOk()->assertJsonPath('data.end_time', '10:30');
    }

    public function test_user_cannot_modify_someone_elses_booking(): void
    {
        $booking = Booking::factory()->create(['room_id' => $this->room->id]);

        $this->actingAs($this->user)->putJson("/api/bookings/{$booking->id}", ['title' => 'x'])->assertForbidden();
        $this->actingAs($this->user)->postJson("/api/bookings/{$booking->id}/cancel")->assertForbidden();
    }

    public function test_admin_can_cancel_any_booking(): void
    {
        $booking = Booking::factory()->create(['room_id' => $this->room->id]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson("/api/bookings/{$booking->id}/cancel", ['reason' => 'Dipakai direksi'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_by', $admin->name);
    }

    public function test_schedule_returns_rooms_with_bookings_for_date(): void
    {
        $this->book()->assertCreated();

        $this->actingAs($this->user)->getJson("/api/schedule?date={$this->tomorrow}")
            ->assertOk()
            ->assertJsonPath('rooms.0.id', $this->room->id)
            ->assertJsonCount(1, 'rooms.0.bookings');
    }

    public function test_booking_type_defaults_to_internal_and_accepts_external(): void
    {
        $this->book()->assertCreated()->assertJsonPath('data.type', 'internal');

        $this->book(['start_at' => "{$this->tomorrow} 11:00", 'end_at' => "{$this->tomorrow} 12:00", 'type' => 'external'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'external')
            ->assertJsonPath('data.type_label', 'Eksternal');

        $this->book(['type' => 'lainnya'])->assertJsonValidationErrors('type');
    }

    public function test_schedule_supports_date_range(): void
    {
        $this->book()->assertCreated();
        $this->book(['start_at' => '2026-10-02 09:00', 'end_at' => '2026-10-02 10:00'])->assertCreated();
        $this->book(['start_at' => '2026-10-20 09:00', 'end_at' => '2026-10-20 10:00'])->assertCreated();

        $this->actingAs($this->user)->getJson('/api/schedule?from=2026-09-27&to=2026-10-03')
            ->assertOk()
            ->assertJsonPath('from', '2026-09-27')
            ->assertJsonCount(2, 'rooms.0.bookings');

        $this->actingAs($this->user)->getJson('/api/schedule?from=2026-09-01&to=2026-12-01')
            ->assertJsonValidationErrors('to');
    }

    public function test_availability_marks_booked_rooms(): void
    {
        $this->book()->assertCreated();
        $free = Room::factory()->create(['floor' => 99]);

        $data = collect($this->actingAs($this->user)->getJson(
            "/api/rooms/availability?start_at={$this->tomorrow} 09:30&end_at={$this->tomorrow} 10:30"
        )->assertOk()->json('data'))->keyBy('id');

        $this->assertFalse($data[$this->room->id]['available']);
        $this->assertTrue($data[$free->id]['available']);
    }
}
