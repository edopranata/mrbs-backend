<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecurringBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        // Senin, 5 Okt 2026 pukul 08:00
        $this->travelTo(Carbon::parse('2026-10-05 08:00'));
        $this->user = User::factory()->create();
        $this->room = Room::factory()->create(['capacity' => 10]);
    }

    private function bookWeekly(int $weeks, array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->postJson('/api/bookings', [
            'room_id' => $this->room->id,
            'title' => 'Weekly Sync',
            'start_at' => '2026-10-06 09:00',
            'end_at' => '2026-10-06 10:00',
            'repeat_weeks' => $weeks,
            ...$overrides,
        ]);
    }

    public function test_weekly_booking_creates_one_booking_per_week_in_same_series(): void
    {
        $response = $this->bookWeekly(4)->assertCreated()->assertJsonCount(4, 'data')->assertJsonPath('skipped', []);

        $this->assertSame(
            ['2026-10-06', '2026-10-13', '2026-10-20', '2026-10-27'],
            array_column($response->json('data'), 'date'),
        );
        $this->assertCount(1, array_unique(array_column($response->json('data'), 'series_id')));
        $this->assertTrue($response->json('data.0.is_recurring'));
    }

    public function test_repeat_weeks_is_limited_by_config(): void
    {
        config(['mrbs.max_repeat_weeks' => 4]);

        $this->bookWeekly(5)->assertJsonValidationErrors('repeat_weeks');
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_conflict_in_any_week_rejects_whole_series_by_default(): void
    {
        Booking::factory()->create([
            'room_id' => $this->room->id,
            'start_at' => '2026-10-20 09:30',
            'end_at' => '2026-10-20 10:30',
        ]);

        $this->bookWeekly(4)
            ->assertJsonValidationErrors('repeat_weeks')
            ->assertJsonPath('errors.repeat_weeks.0', fn ($msg) => str_contains($msg, '1 dari 4 tanggal'));

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_conflicting_weeks_can_be_skipped(): void
    {
        Booking::factory()->create([
            'room_id' => $this->room->id,
            'start_at' => '2026-10-20 09:30',
            'end_at' => '2026-10-20 10:30',
        ]);

        $this->bookWeekly(4, ['skip_conflicts' => true])
            ->assertCreated()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('skipped.0.date', '2026-10-20');
    }

    public function test_regular_user_cannot_repeat_beyond_advance_limit(): void
    {
        config(['mrbs.max_advance_days' => 14, 'mrbs.max_repeat_weeks' => 8]);

        // Minggu ke-3 (20 Okt) dan ke-4 (27 Okt) melewati batas 14 hari
        $this->bookWeekly(4, ['skip_conflicts' => true])->assertCreated()->assertJsonCount(2, 'data');

        // Admin tidak dibatasi
        $this->bookWeekly(4, ['room_id' => Room::factory()->create()->id], User::factory()->admin()->create())
            ->assertCreated()
            ->assertJsonCount(4, 'data');
    }

    public function test_occurrences_preview_reports_each_week(): void
    {
        Booking::factory()->create([
            'room_id' => $this->room->id,
            'start_at' => '2026-10-13 09:00',
            'end_at' => '2026-10-13 10:00',
        ]);

        $data = $this->actingAs($this->user)->getJson('/api/bookings/occurrences?'.http_build_query([
            'room_id' => $this->room->id,
            'start_at' => '2026-10-06 09:00',
            'end_at' => '2026-10-06 10:00',
            'repeat_weeks' => 3,
        ]))->assertOk()->json('data');

        $this->assertSame([true, false, true], array_column($data, 'available'));
        $this->assertStringContainsString('bentrok', $data[1]['reason']);
    }

    public function test_availability_checks_all_weeks(): void
    {
        Booking::factory()->create([
            'room_id' => $this->room->id,
            'start_at' => '2026-10-20 09:00',
            'end_at' => '2026-10-20 10:00',
        ]);
        $query = 'start_at=2026-10-06 09:00&end_at=2026-10-06 10:00';

        $single = collect($this->actingAs($this->user)->getJson("/api/rooms/availability?{$query}")->json('data'))->firstWhere('id', $this->room->id);
        $this->assertTrue($single['available']);

        $weekly = collect($this->actingAs($this->user)->getJson("/api/rooms/availability?{$query}&repeat_weeks=3")->json('data'))->firstWhere('id', $this->room->id);
        $this->assertFalse($weekly['available']);
        $this->assertSame(['2026-10-20'], $weekly['conflict_dates']);
    }

    public function test_cancel_this_and_following_weeks(): void
    {
        $ids = array_column($this->bookWeekly(4)->json('data'), 'id');

        $this->actingAs($this->user)->getJson("/api/bookings/{$ids[1]}")
            ->assertJsonPath('series.total', 4)
            ->assertJsonPath('series.position', 2)
            ->assertJsonPath('series.following_cancellable', 3);

        $this->actingAs($this->user)->postJson("/api/bookings/{$ids[1]}/cancel", ['scope' => 'following'])
            ->assertOk()
            ->assertJsonPath('cancelled_count', 3);

        $statuses = Booking::orderBy('start_at')->pluck('status')->map->value->all();
        $this->assertSame(['confirmed', 'cancelled', 'cancelled', 'cancelled'], $statuses);
    }

    public function test_cancel_single_week_keeps_rest_of_series(): void
    {
        $ids = array_column($this->bookWeekly(3)->json('data'), 'id');

        $this->actingAs($this->user)->postJson("/api/bookings/{$ids[0]}/cancel")->assertJsonPath('cancelled_count', 1);

        $this->assertSame(2, Booking::confirmed()->count());
    }

    public function test_other_user_cannot_cancel_series(): void
    {
        $ids = array_column($this->bookWeekly(2)->json('data'), 'id');

        $this->actingAs(User::factory()->create())->postJson("/api/bookings/{$ids[0]}/cancel", ['scope' => 'following'])
            ->assertForbidden();
    }

    public function test_repeat_is_not_allowed_when_updating(): void
    {
        $id = $this->bookWeekly(2)->json('data.0.id');

        $this->actingAs($this->user)->putJson("/api/bookings/{$id}", ['repeat_weeks' => 3])
            ->assertJsonValidationErrors('repeat_weeks');
    }
}
