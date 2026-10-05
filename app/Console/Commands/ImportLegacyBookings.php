<?php

namespace App\Console\Commands;

use App\Services\LegacyBookingImporter;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Migrasi booking dari MRBS lama (mrbs-code). Alur:
 *   1. php artisan mrbs:import-legacy --make-maps   → buat users.csv & rooms.csv untuk diisi
 *   2. isi kolom new_username / new_room_code di kedua file
 *   3. php artisan mrbs:import-legacy --dry-run     → periksa ringkasan tanpa menyimpan
 *   4. php artisan mrbs:import-legacy               → impor (aman diulang; entri yang sudah diimpor dilewati)
 */
class ImportLegacyBookings extends Command
{
    protected $signature = 'mrbs:import-legacy
        {--make-maps : Buat/perbarui file pemetaan user & ruangan dari database lama, lalu berhenti}
        {--dry-run : Tampilkan ringkasan tanpa menyimpan apa pun}
        {--fallback-user= : Username di aplikasi ini untuk booking yang pembuatnya belum dipetakan (default: dilewati)}
        {--allow-conflicts : Tetap impor booking yang bentrok dengan booking yang sudah ada di aplikasi ini}
        {--map-dir= : Folder file pemetaan (default: storage/app/private/legacy)}
        {--connection=legacy : Koneksi database MRBS lama (lihat LEGACY_DB_* di .env)}';

    protected $description = 'Migrasi booking dari MRBS lama (mrbs-code) ke aplikasi ini';

    public function handle(): int
    {
        $dir = rtrim($this->option('map-dir') ?: storage_path('app/private/legacy'), '/');
        $importer = new LegacyBookingImporter($this->option('connection'));

        try {
            if ($this->option('make-maps')) {
                $counts = $importer->makeMaps($dir);
                $this->info("File pemetaan dibuat/diperbarui di {$dir}:");
                $this->line('  - '.LegacyBookingImporter::USER_MAP." ({$counts['users']} pembuat booking): isi kolom new_username");
                $this->line('  - '.LegacyBookingImporter::ROOM_MAP." ({$counts['rooms']} ruangan): periksa kolom new_room_code");
                $this->line('Pemetaan yang sudah diisi tidak ditimpa. Lanjutkan dengan --dry-run.');

                return self::SUCCESS;
            }

            $dryRun = (bool) $this->option('dry-run');
            $report = $importer->import($dir, [
                'dry_run' => $dryRun,
                'fallback_user' => $this->option('fallback-user'),
                'allow_conflicts' => (bool) $this->option('allow-conflicts'),
            ]);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Simulasi impor (tidak ada yang disimpan):' : 'Impor selesai:');
        $this->table(['Keterangan', 'Jumlah'], [
            ['Booking di MRBS lama', $report['total']],
            ['Sudah pernah diimpor (dilewati)', $report['already']],
            [$dryRun ? 'Akan diimpor' : 'Diimpor', $dryRun ? $report['importable'] : $report['imported']],
            ['  · berulang (seri mingguan)', $report['recurring']],
            ['  · tentative di MRBS lama (diimpor sebagai biasa)', $report['tentative']],
            ['  · durasi ≥ 24 jam (diimpor apa adanya)', $report['long']],
            ['  · dengan No. WA PIC (ditambahkan ke deskripsi)', $report['with_wa']],
            ['  · memakai user cadangan', $report['fallback']],
            ['Dilewati: pembuat belum dipetakan', array_sum($report['unmapped_users'])],
            ['Dilewati: ruangan belum dipetakan', array_sum($report['unmapped_rooms'])],
            [$this->option('allow-conflicts') ? 'Bentrok (tetap diimpor)' : 'Dilewati: bentrok dengan booking yang ada', $report['conflict_count']],
        ]);

        if ($report['unmapped_users']) {
            $this->warn('Pembuat booking yang belum dipetakan (isi new_username di '.LegacyBookingImporter::USER_MAP.'):');
            foreach ($report['unmapped_users'] as $name => $count) {
                $this->line("  - {$name}: {$count} booking");
            }
        }
        foreach ($report['unknown_user_targets'] as $old => $new) {
            $this->warn("  ! {$old} → \"{$new}\": username tersebut tidak ada di aplikasi ini");
        }
        if ($report['unmapped_rooms']) {
            $this->warn('Ruangan yang belum dipetakan (isi new_room_code di '.LegacyBookingImporter::ROOM_MAP.'):');
            foreach ($report['unmapped_rooms'] as $name => $count) {
                $this->line("  - {$name}: {$count} booking");
            }
        }
        foreach ($report['unknown_room_targets'] as $old => $code) {
            $this->warn("  ! ruangan lama #{$old} → \"{$code}\": kode ruangan tersebut tidak ada di aplikasi ini");
        }
        if ($report['conflicts']) {
            $this->warn('Contoh booking yang bentrok:');
            foreach ($report['conflicts'] as $line) {
                $this->line("  - {$line}");
            }
        }

        return self::SUCCESS;
    }
}
