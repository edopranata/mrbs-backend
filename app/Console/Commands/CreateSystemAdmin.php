<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Membuat akun System Admin di server (mis. cloud hosting), tanpa memakai akun
 * default dari seeder. Password ditanyakan secara tersembunyi bila tidak diberikan.
 */
class CreateSystemAdmin extends Command
{
    protected $signature = 'mrbs:create-sysadmin
        {--name= : Nama lengkap}
        {--username= : Username untuk login}
        {--email= : Alamat email}
        {--password= : Password (tidak disarankan karena tersimpan di riwayat shell; kosongkan agar ditanyakan)}
        {--force : Bila username sudah ada, jadikan System Admin, aktifkan, dan ganti passwordnya}';

    protected $description = 'Buat akun System Admin (atau promosikan akun yang ada dengan --force)';

    public function handle(): int
    {
        $username = strtolower(trim((string) ($this->option('username') ?? $this->ask('Username (untuk login)'))));
        $existing = User::where('username', $username)->first();

        if ($existing && ! $this->option('force')) {
            $this->error("Username \"{$username}\" sudah dipakai. Gunakan --force untuk menjadikannya System Admin dan mengganti passwordnya.");

            return self::FAILURE;
        }

        $name = $this->option('name') ?? $this->ask('Nama lengkap', $existing?->name ?? 'System Administrator');
        $email = $this->option('email') ?? $this->ask('Email', $existing?->email);
        $password = $this->option('password') ?? $this->askPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $validator = Validator::make(
            compact('name', 'username', 'email', 'password'),
            [
                'name' => ['required', 'string', 'max:255'],
                'username' => [
                    'required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/',
                    Rule::unique('users', 'username')->ignore($existing),
                ],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($existing)],
                'password' => ['required', 'string', Password::min(8)],
            ],
            ['username.regex' => 'Username hanya boleh berisi huruf kecil, angka, titik (.), garis bawah (_), atau strip (-).'],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = $existing ?? new User;
        $user->fill([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'role' => UserRole::SystemAdmin,
            'is_active' => true,
        ])->save();

        // Sesi lama dicabut bila akun yang sudah ada diganti passwordnya.
        if ($existing) {
            $user->tokens()->delete();
        }

        $this->components->info($existing
            ? "Akun \"{$username}\" sekarang System Admin dan passwordnya telah diganti."
            : "System Admin \"{$username}\" berhasil dibuat.");
        $this->table(['Nama', 'Username', 'Email', 'Level'], [[$user->name, $user->username, $user->email, $user->role->label()]]);

        return self::SUCCESS;
    }

    private function askPassword(): ?string
    {
        if (! $this->input->isInteractive()) {
            $this->error('Password wajib diisi. Jalankan secara interaktif atau gunakan --password.');

            return null;
        }

        $password = (string) $this->secret('Password (min. 8 karakter)');

        if ($password !== (string) $this->secret('Ulangi password')) {
            $this->error('Konfirmasi password tidak cocok.');

            return null;
        }

        return $password;
    }
}
