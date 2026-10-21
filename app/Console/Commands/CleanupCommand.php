<?php

namespace App\Console\Commands;

use App\Services\Maintenance;
use App\Support\Audit;
use Illuminate\Console\Command;

/**
 * Pembersihan harian (dijadwalkan di routes/console.php): audit log & file log lama, cache
 * kedaluwarsa, sesi lama, dan pengosongan cache berkala sesuai menu Pengaturan.
 */
class CleanupCommand extends Command
{
    protected $signature = 'mrbs:cleanup {--clear-cache : Kosongkan seluruh cache sekarang, tanpa menunggu jadwalnya}';

    protected $description = 'Bersihkan audit log, file log, cache, dan sesi lama sesuai menu Pengaturan';

    public function handle(Maintenance $maintenance): int
    {
        $report = $maintenance->run((bool) $this->option('clear-cache'));
        $summary = Maintenance::summary($report);
        $this->info($summary);

        if (Maintenance::didSomething($report)) {
            Audit::system('system.cleanup', 'Pembersihan otomatis. '.$summary);
        }

        return self::SUCCESS;
    }
}
