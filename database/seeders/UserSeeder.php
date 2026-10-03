<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Akun default (login memakai username). Memakai firstOrCreate agar menjalankan ulang seeder tidak
     * menimpa password/data akun yang sudah diubah.
     */
    public function run(): void
    {
        // Akun default berpassword "password" tidak boleh ada di server produksi.
        if (app()->isProduction()) {
            $this->command?->warn('Akun default dilewati (APP_ENV=production). Buat System Admin dengan: php artisan mrbs:create-sysadmin');

            return;
        }

        User::firstOrCreate(['username' => 'sysadmin'], [
            'email' => 'sysadmin@kantor.test',
            'name' => 'System Administrator',
            'password' => 'password',
            'role' => UserRole::SystemAdmin,
            'department' => 'IT',
            'is_active' => true,
        ]);

        User::firstOrCreate(['username' => 'admin'], [
            'email' => 'admin@kantor.test',
            'name' => 'Administrator',
            'password' => 'password',
            'role' => UserRole::Admin,
            'department' => 'General Affair',
            'is_active' => true,
        ]);

        User::firstOrCreate(['username' => 'user'], [
            'email' => 'user@kantor.test',
            'name' => 'Pegawai Contoh',
            'password' => 'password',
            'role' => UserRole::User,
            'department' => 'IT',
            'is_active' => true,
        ]);
    }
}
