<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TodayBookingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-05 10:15'));
        $room = Room::factory()->create();
        $make = fn (string $title, string $start, string $end) => Booking::factory()->create([
            'room_id' => $room->id, 'title' => $title, 'start_at' => $start, 'end_at' => $end,
        ]);

        $make('Selesai', '2026-10-05 08:00', '2026-10-05 09:00');
        $make('Berlangsung', '2026-10-05 10:00', '2026-10-05 11:00');
        $make('Nanti Siang', '2026-10-05 13:00', '2026-10-05 14:00');
        $make('Pagi Ini', '2026-10-05 11:00', '2026-10-05 12:00');
        $make('Besok', '2026-10-06 10:00', '2026-10-06 11:00');
        $make('Kemarin', '2026-10-04 10:00', '2026-10-04 11:00');
        Booking::factory()->cancelled()->create([
            'room_id' => $room->id, 'title' => 'Dibatalkan', 'start_at' => '2026-10-05 15:00', 'end_at' => '2026-10-05 16:00',
        ]);
    }

    public function test_admin_sees_only_ongoing_and_upcoming_bookings_today(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())->getJson('/api/bookings/today')->assertOk();

        $this->assertSame('2026-10-05', $response->json('date'));
        $this->assertSame(['Berlangsung'], array_column($response->json('ongoing'), 'title'));
        $this->assertSame(['Pagi Ini', 'Nanti Siang'], array_column($response->json('upcoming'), 'title'));
    }

    public function test_finished_bookings_disappear_as_time_passes(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 11:30'));

        $response = $this->actingAs(User::factory()->systemAdmin()->create())->getJson('/api/bookings/today')->assertOk();

        $this->assertSame(['Pagi Ini'], array_column($response->json('ongoing'), 'title'));
        $this->assertSame(['Nanti Siang'], array_column($response->json('upcoming'), 'title'));
    }

    public function test_regular_user_cannot_access(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/bookings/today')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/bookings/today')->assertUnauthorized();
    }
}
