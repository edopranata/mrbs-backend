<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identitas Aplikasi
    |--------------------------------------------------------------------------
    |
    | Semua nilai di file ini adalah DEFAULT. System Admin dapat menimpanya dari
    | menu Pengaturan (disimpan di tabel `settings`, lihat App\Services\AppSettings).
    |
    */

    'app_name' => env('MRBS_APP_NAME', 'MRBS'),

    'app_subtitle' => env('MRBS_APP_SUBTITLE', 'Booking Ruang Rapat'),

    // index.html frontend Vue hasil `npm run build:laravel`, disajikan untuk semua URL halaman.
    'spa_index' => public_path('app/index.html'),

    /*
    |--------------------------------------------------------------------------
    | Jam Operasional Ruang Rapat
    |--------------------------------------------------------------------------
    |
    | Booking hanya boleh dibuat di antara jam buka dan jam tutup (format H:i).
    |
    */

    'open_time' => env('MRBS_OPEN_TIME', '07:00'),

    'close_time' => env('MRBS_CLOSE_TIME', '20:00'),

    /*
    |--------------------------------------------------------------------------
    | Aturan Durasi & Pemesanan
    |--------------------------------------------------------------------------
    */

    // Interval jam booking (menit). Jam mulai & selesai harus kelipatan nilai ini,
    // misal 30 -> 09:00, 09:30, 10:00, dst.
    'slot_minutes' => (int) env('MRBS_SLOT_MINUTES', 30),

    // Durasi minimal satu booking (menit).
    'min_duration' => (int) env('MRBS_MIN_DURATION', 30),

    // Durasi maksimal satu booking (menit).
    'max_duration' => (int) env('MRBS_MAX_DURATION', 480),

    // Booking berulang: maksimal berapa minggu (termasuk minggu pertama).
    'max_repeat_weeks' => (int) env('MRBS_MAX_REPEAT_WEEKS', 8),

    // Seberapa jauh ke depan user biasa boleh memesan (hari). Admin tidak dibatasi.
    'max_advance_days' => (int) env('MRBS_MAX_ADVANCE_DAYS', 60),

];
