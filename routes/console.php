<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pemeliharaan harian: audit log & file log lama, cache, dan sesi (batasnya di menu Pengaturan).
// Di server, cron `scripts/cron-schedule.sh` menjalankan `schedule:run` setiap menit.
Schedule::command('mrbs:cleanup')
    ->dailyAt(config('mrbs.cleanup_time'))
    ->withoutOverlapping(60);
