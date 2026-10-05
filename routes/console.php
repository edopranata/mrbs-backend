<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Masa transisi: salin booking dari MRBS lama secara berkala (LEGACY_SYNC_ENABLED=true).
// Di server, cron `php artisan schedule:run` dijalankan tiap menit (hPanel → Cron Jobs).
if (config('mrbs.legacy_sync.enabled')) {
    Schedule::command('mrbs:sync-legacy')
        ->cron('*/'.min(59, config('mrbs.legacy_sync.interval')).' * * * *')
        ->withoutOverlapping(30)
        ->appendOutputTo(storage_path('logs/legacy-sync.log'));
}
