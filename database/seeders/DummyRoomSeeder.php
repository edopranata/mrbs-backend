<?php

namespace Database\Seeders;

use App\Models\Room;
use Illuminate\Database\Seeder;

/**
 * Data ruangan FIKTIF untuk development, demo, dan repository publik.
 *
 * Data ruangan sebenarnya disimpan di RoomSeeder.php yang sengaja tidak ikut git
 * (lihat .gitignore). Bila file itu tidak ada, DatabaseSeeder memakai seeder ini.
 * Untuk membuat RoomSeeder.php sendiri, salin file ini lalu ganti nama class & datanya.
 */
class DummyRoomSeeder extends Seeder
{
    public function run(): void
    {
        $rooms = [
            ['code' => 'R101', 'name' => 'Ruang Rapat Anggrek', 'floor' => 1, 'capacity' => 8, 'color' => '#4f46e5',
                'facilities' => ['TV / Layar', 'AC', 'Whiteboard', 'Wi-Fi']],
            ['code' => 'R102', 'name' => 'Ruang Rapat Bougenville', 'floor' => 1, 'capacity' => 12, 'color' => '#0891b2',
                'facilities' => ['Proyektor', 'AC', 'Whiteboard', 'Wi-Fi']],
            ['code' => 'R201', 'name' => 'Ruang Rapat Cempaka', 'floor' => 2, 'capacity' => 10, 'color' => '#059669',
                'facilities' => ['TV / Layar', 'AC', 'Wi-Fi']],
            ['code' => 'R202', 'name' => 'Ruang Rapat Dahlia', 'floor' => 2, 'capacity' => 16, 'color' => '#d97706',
                'facilities' => ['Proyektor', 'AC', 'Whiteboard', 'Wi-Fi', 'Video Conference']],
            ['code' => 'R301', 'name' => 'Ruang Rapat Edelweis', 'floor' => 3, 'capacity' => 6, 'color' => '#db2777',
                'facilities' => ['TV / Layar', 'AC', 'Wi-Fi']],
            ['code' => 'R302', 'name' => 'Ruang Rapat Flamboyan', 'floor' => 3, 'capacity' => 20, 'color' => '#7c3aed',
                'facilities' => ['Proyektor', 'AC', 'Whiteboard', 'Wi-Fi', 'Video Conference']],
            ['code' => 'R401', 'name' => 'Aula Gardenia', 'floor' => 4, 'capacity' => 40, 'color' => '#dc2626',
                'facilities' => ['Proyektor', 'AC', 'Sound System', 'Wi-Fi', 'Video Conference'],
                'description' => 'Ruang besar untuk rapat umum dan pertemuan dengan tamu.'],
        ];

        foreach ($rooms as $room) {
            Room::updateOrCreate(['code' => $room['code']], $room);
        }
    }
}
