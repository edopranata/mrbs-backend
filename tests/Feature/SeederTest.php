<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use Database\Seeders\DemoBookingSeeder;
use Database\Seeders\DummyRoomSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_dummy_room_seeder_creates_fictional_rooms_idempotently(): void
    {
        $this->seed(DummyRoomSeeder::class);
        $this->seed(DummyRoomSeeder::class);

        $this->assertSame(7, Room::count());
        $this->assertSame([1, 2, 3, 4], Room::distinct()->orderBy('floor')->pluck('floor')->all());
    }

    public function test_demo_bookings_work_with_dummy_rooms(): void
    {
        $this->seed([DummyRoomSeeder::class, UserSeeder::class, DemoBookingSeeder::class]);

        $series = Booking::whereNotNull('series_id')->get();
        $this->assertCount(4, $series);
        $this->assertSame('R401', $series->first()->room->code);
        $this->assertGreaterThan(4, Booking::count());
    }
}
