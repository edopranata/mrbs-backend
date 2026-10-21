<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan aktivitas (siapa melakukan apa, kapan, dari mana). Hanya bisa ditambah, tidak diubah;
 * dihapus otomatis setelah masa simpan (Pengaturan → Pemeliharaan) oleh `mrbs:cleanup`.
 */
#[Fillable(['user_id', 'user_name', 'action', 'subject_type', 'subject_id', 'description', 'changes', 'meta', 'ip_address', 'user_agent', 'created_at'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** Kelompok aktivitas (awalan `action`) untuk filter. */
    public const CATEGORIES = [
        'auth' => 'Akun & Login',
        'booking' => 'Booking',
        'room' => 'Ruangan',
        'user' => 'User',
        'settings' => 'Pengaturan',
        'system' => 'Sistem',
    ];

    public const ACTIONS = [
        'auth.login' => 'Login',
        'auth.login_failed' => 'Login gagal',
        'auth.logout' => 'Logout',
        'auth.profile_updated' => 'Ubah profil',
        'auth.password_changed' => 'Ganti password',
        'booking.created' => 'Buat booking',
        'booking.updated' => 'Ubah booking',
        'booking.cancelled' => 'Batalkan booking',
        'booking.deleted' => 'Hapus booking',
        'room.created' => 'Tambah ruangan',
        'room.updated' => 'Ubah ruangan',
        'room.deleted' => 'Hapus ruangan',
        'user.created' => 'Tambah user',
        'user.updated' => 'Ubah user',
        'user.deleted' => 'Hapus user',
        'settings.updated' => 'Ubah pengaturan',
        'settings.reset' => 'Reset pengaturan',
        'system.cleanup' => 'Pembersihan data',
    ];

    /** Nama kolom yang mudah dibaca untuk daftar perubahan. */
    public const FIELDS = [
        'title' => 'Judul',
        'description' => 'Deskripsi',
        'type' => 'Jenis rapat',
        'room' => 'Ruangan',
        'start_at' => 'Mulai',
        'end_at' => 'Selesai',
        'participants' => 'Peserta',
        'code' => 'Kode',
        'name' => 'Nama',
        'floor' => 'Lantai',
        'capacity' => 'Kapasitas',
        'facilities' => 'Fasilitas',
        'color' => 'Warna',
        'is_active' => 'Aktif',
        'username' => 'Username',
        'email' => 'Email',
        'role' => 'Level',
        'department' => 'Department',
        'phone' => 'No. telepon',
        'password' => 'Password',
        'app_name' => 'Nama aplikasi',
        'app_subtitle' => 'Subjudul',
        'open_time' => 'Jam buka',
        'close_time' => 'Jam tutup',
        'slot_minutes' => 'Interval slot (menit)',
        'min_duration' => 'Durasi minimal (menit)',
        'max_duration' => 'Durasi maksimal (menit)',
        'max_advance_days' => 'Batas hari pemesanan',
        'max_repeat_weeks' => 'Batas minggu berulang',
        'audit_retention_days' => 'Masa simpan audit log (hari)',
        'log_retention_days' => 'Masa simpan file log (hari)',
        'cache_clear_days' => 'Kosongkan cache tiap (hari)',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): string
    {
        return strstr($this->action, '.', true) ?: $this->action;
    }
}
