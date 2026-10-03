<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Akun default. Memakai firstOrCreate agar menjalankan ulang seeder tidak
     * menimpa password/data akun yang sudah diubah.
     */
    public function run(): void
    {
        User::firstOrCreate(['email' => 'sysadmin@kantor.test'], [
            'name' => 'System Administrator',
            'password' => 'password',
            'role' => UserRole::SystemAdmin,
            'department' => 'IT',
            'is_active' => true,
        ]);

        User::firstOrCreate(['email' => 'admin@kantor.test'], [
            'name' => 'Administrator',
            'password' => 'password',
            'role' => UserRole::Admin,
            'department' => 'General Affair',
            'is_active' => true,
        ]);

        User::firstOrCreate(['email' => 'user@kantor.test'], [
            'name' => 'Pegawai Contoh',
            'password' => 'password',
            'role' => UserRole::User,
            'department' => 'IT',
            'is_active' => true,
        ]);
    }
}
