<?php

namespace App\Console\Commands;

use App\Services\LegacyBookingImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sinkronisasi satu arah MRBS lama → aplikasi ini selama masa transisi. Dijalankan scheduler
 * (cron `php artisan schedule:run` tiap menit) bila LEGACY_SYNC_ENABLED=true:
 * booking baru diimpor, booking yang diubah di MRBS lama diperbarui, dan booking mendatang yang
 * dihapus di MRBS lama dibatalkan. Memakai file pemetaan yang sama dengan `mrbs:import-legacy`.
 */
class SyncLegacyBookings extends Command
{
    protected $signature = 'mrbs:sync-legacy
        {--check : Hanya periksa koneksi database lama & file pemetaan, tanpa sinkronisasi}
        {--dry-run : Tampilkan apa yang akan berubah tanpa menyimpan apa pun}
        {--max-cancellations= : Batas pembatalan otomatis sekali jalan (default LEGACY_SYNC_MAX_CANCELLATIONS)}';

    protected $description = 'Sinkronkan booking dari MRBS lama (masa transisi; dijadwalkan bila LEGACY_SYNC_ENABLED=true)';

    public function handle(): int
    {
        $dir = rtrim(config('mrbs.legacy_sync.map_dir'), '/');

        if ($this->option('check')) {
            return $this->check($dir);
        }

        try {
            $report = (new LegacyBookingImporter)->import($dir, [
                'sync' => true,
                'dry_run' => (bool) $this->option('dry-run'),
                'max_cancellations' => (int) ($this->option('max-cancellations') ?? config('mrbs.legacy_sync.max_cancellations')),
            ]);
        } catch (Throwable $e) {
            Log::error('Sinkronisasi MRBS lama gagal: '.$e->getMessage());
            $this->error('Sinkronisasi gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $dry = $this->option('dry-run');
        $summary = sprintf(
            '%s: %d entri lama, %d baru, %d diperbarui, %d dibatalkan (dihapus di MRBS lama), %d bentrok dilewati, %d pembuat & %d ruangan belum dipetakan',
            $dry ? 'Simulasi sinkronisasi' : 'Sinkronisasi MRBS lama',
            $report['total'],
            $dry ? $report['importable'] : $report['imported'],
            $dry ? $report['changed'] : $report['updated'],
            $dry ? $report['deleted_upstream'] : $report['cancelled'],
            $report['conflict_count'],
            array_sum($report['unmapped_users']),
            array_sum($report['unmapped_rooms']),
        );
        $this->info($summary);

        $warnings = array_filter([
            $report['cancel_blocked'],
            $report['unmapped_users'] ? 'Pembuat belum dipetakan: '.implode(', ', array_keys($report['unmapped_users'])) : null,
            $report['unmapped_rooms'] ? 'Ruangan belum dipetakan: '.implode(', ', array_keys($report['unmapped_rooms'])) : null,
            $report['conflicts'] ? 'Bentrok: '.implode('; ', array_slice($report['conflicts'], 0, 5)) : null,
        ]);
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        if (! $dry) {
            $changed = $report['imported'] + $report['updated'] + $report['cancelled'];
            if ($changed > 0 || $warnings) {
                Log::log($warnings ? 'warning' : 'info', $summary, ['peringatan' => array_values($warnings)]);
            }
        }

        return self::SUCCESS;
    }

    /** Pemeriksaan untuk persiapan di server: koneksi, isi database lama, dan file pemetaan. */
    private function check(string $dir): int
    {
        $ok = true;
        $this->line('Sinkronisasi terjadwal: '.(config('mrbs.legacy_sync.enabled')
            ? 'AKTIF, tiap '.config('mrbs.legacy_sync.interval').' menit'
            : 'nonaktif (LEGACY_SYNC_ENABLED=false)'));

        try {
            $count = DB::connection('legacy')->table('entry')->count();
            $this->info("✔ Database MRBS lama terhubung ({$count} booking).");
        } catch (Throwable $e) {
            $ok = false;
            $this->error('✘ Database MRBS lama tidak bisa dihubungi: '.$e->getMessage());
        }

        foreach ([LegacyBookingImporter::USER_MAP, LegacyBookingImporter::ROOM_MAP] as $file) {
            if (is_file("{$dir}/{$file}")) {
                $this->info("✔ {$dir}/{$file}");
            } else {
                $ok = false;
                $this->error("✘ {$dir}/{$file} belum ada (buat dengan mrbs:import-legacy --make-maps atau unggah dari komputer lokal).");
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
