<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // RoomSeeder berisi data ruangan sebenarnya dan tidak ikut git (lihat .gitignore).
            // Bila file tersebut tidak ada (mis. hasil clone repository), pakai data fiktif.
            class_exists(RoomSeeder::class) ? RoomSeeder::class : DummyRoomSeeder::class,
            UserSeeder::class,
        ]);

        // Akun unit kerja asli (tidak ikut git); hanya dijalankan bila file-nya ada.
        if (class_exists(OfficeUserSeeder::class)) {
            $this->call(OfficeUserSeeder::class);
        }

        if (app()->isLocal()) {
            $this->call(DemoBookingSeeder::class);
        }
    }
}
