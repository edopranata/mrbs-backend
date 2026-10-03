<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Contoh booking beberapa hari ke belakang & ke depan (hari kerja), agar tampilan jadwal tidak kosong saat development.
 */
class DemoBookingSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::factory()->count(4)->create();
        $users->push(User::where('email', 'user@kantor.test')->first());

        $templates = [
            ['Daily Standup Tim IT', 9, 0, 30, 'internal'],
            ['Rapat Koordinasi Mingguan', 10, 0, 90, 'internal'],
            ['Review Anggaran Q4', 13, 0, 120, 'internal'],
            ['Interview Kandidat', 14, 30, 60, 'external'],
            ['Presentasi Vendor', 15, 30, 90, 'external'],
        ];

        $days = collect(range(-3, 10))
            ->map(fn (int $offset) => today()->addDays($offset))
            ->reject(fn (Carbon $day) => $day->isWeekend());

        // Contoh booking berulang: rapat manajemen setiap Senin selama 4 minggu,
        // di ruangan pada lantai tertinggi.
        $mainRoom = Room::orderByDesc('floor')->orderBy('code')->first();
        if ($mainRoom) {
            $series = (string) Str::uuid();
            $monday = today()->startOfWeek();
            foreach (range(0, 3) as $week) {
                Booking::create([
                    'room_id' => $mainRoom->id,
                    'user_id' => $users->last()->id,
                    'series_id' => $series,
                    'title' => 'Rapat Manajemen Mingguan',
                    'type' => 'internal',
                    'start_at' => $monday->copy()->addWeeks($week)->setTime(7, 30),
                    'end_at' => $monday->copy()->addWeeks($week)->setTime(8, 30),
                    'participants' => 12,
                ]);
            }
        }

        foreach ($days as $day) {
            Room::all()->each(function (Room $room) use ($day, $templates, $users) {
                collect($templates)->shuffle()->take(rand(1, 3))->sortBy(1)->reduce(
                    function (?Carbon $lastEnd, array $t) use ($day, $room, $users) {
                        $start = $day->copy()->setTime($t[1], $t[2]);
                        if ($lastEnd && $start->lt($lastEnd)) {
                            return $lastEnd;
                        }
                        $end = $start->copy()->addMinutes($t[3]);

                        Booking::create([
                            'room_id' => $room->id,
                            'user_id' => $users->random()->id,
                            'title' => $t[0],
                            'type' => $t[4],
                            'start_at' => $start,
                            'end_at' => $end,
                            'participants' => min($room->capacity, rand(2, 8)),
                        ]);

                        return $end;
                    }
                );
            });
        }
    }
}
