<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * Pembersihan berkala agar aplikasi tetap ringan: audit log & isi file log yang lewat masa simpan,
 * cache kedaluwarsa, sesi & token login lama, dan pengosongan seluruh cache setiap beberapa hari.
 * Batasnya diatur System Admin di menu Pengaturan (config('mrbs.*_days')).
 */
class Maintenance
{
    /** File log yang ditimpa setiap menit oleh cron; tidak perlu dipangkas. */
    private const SKIP_LOGS = ['cron-schedule.log'];

    /**
     * @return array{audit_logs: int, log_files_deleted: int, log_files_trimmed: int, cache_expired: int, sessions: int, tokens: int, cache_cleared: bool}
     */
    public function run(bool $forceCacheClear = false): array
    {
        $now = now();
        $report = [
            'audit_logs' => 0,
            'log_files_deleted' => 0,
            'log_files_trimmed' => 0,
            'cache_expired' => 0,
            'sessions' => 0,
            'tokens' => 0,
            'cache_cleared' => false,
        ];

        if (($days = (int) config('mrbs.audit_retention_days')) > 0) {
            $report['audit_logs'] = AuditLog::where('created_at', '<', $now->copy()->subDays($days))->delete();
        }

        if (($days = (int) config('mrbs.log_retention_days')) > 0) {
            [$report['log_files_deleted'], $report['log_files_trimmed']] = $this->cleanLogs($now->copy()->subDays($days));
        }

        $report['cache_expired'] = $this->purgeExpiredCache();
        $report['sessions'] = $this->purgeExpiredSessions();

        // Token yang sudah tidak berlaku (lihat AppServiceProvider) tidak perlu disimpan.
        if (($days = (int) config('mrbs.security.token_idle_days')) > 0) {
            $cutoff = $now->copy()->subDays($days);
            $report['tokens'] = PersonalAccessToken::query()
                ->where(fn ($q) => $q->where('last_used_at', '<', $cutoff)
                    ->orWhere(fn ($q) => $q->whereNull('last_used_at')->where('created_at', '<', $cutoff)))
                ->delete();
        }

        $state = $this->state();
        $every = (int) config('mrbs.cache_clear_days');
        $lastClear = isset($state['cache_cleared_at']) ? Carbon::parse($state['cache_cleared_at']) : null;
        // Dibandingkan per tanggal agar jadwal harian yang selisih beberapa detik tetap terhitung.
        $due = $every > 0 && (! $lastClear || $lastClear->toDateString() <= $now->copy()->subDays($every)->toDateString());

        if ($forceCacheClear || $due) {
            Artisan::call('cache:clear');
            Artisan::call('view:clear');
            $report['cache_cleared'] = true;
            $state['cache_cleared_at'] = $now->toIso8601String();
        }

        $state['last_run_at'] = $now->toIso8601String();
        $state['last_summary'] = self::summary($report);
        $this->saveState($state);

        return $report;
    }

    /** Jejak pembersihan terakhir (untuk ditampilkan di menu Pengaturan). */
    public function status(): array
    {
        $state = $this->state();

        return [
            'time' => config('mrbs.cleanup_time'),
            'last_run_at' => $state['last_run_at'] ?? null,
            'last_summary' => $state['last_summary'] ?? null,
            'cache_cleared_at' => $state['cache_cleared_at'] ?? null,
        ];
    }

    public static function didSomething(array $report): bool
    {
        return array_filter($report) !== [];
    }

    /** "3 audit log, 1 file log dihapus, 120 cache kedaluwarsa; seluruh cache dikosongkan" */
    public static function summary(array $report): string
    {
        $parts = array_filter([
            $report['audit_logs'] ? "{$report['audit_logs']} audit log" : null,
            $report['log_files_deleted'] ? "{$report['log_files_deleted']} file log" : null,
            $report['log_files_trimmed'] ? "isi lama {$report['log_files_trimmed']} file log" : null,
            $report['cache_expired'] ? "{$report['cache_expired']} cache kedaluwarsa" : null,
            $report['sessions'] ? "{$report['sessions']} sesi lama" : null,
            ($report['tokens'] ?? 0) ? "{$report['tokens']} token login kedaluwarsa" : null,
        ]);
        $text = $parts ? 'Dihapus: '.implode(', ', $parts) : 'Tidak ada data lama yang perlu dihapus';

        return $report['cache_cleared'] ? "{$text}; seluruh cache dikosongkan." : "{$text}.";
    }

    /**
     * File log yang tidak berubah sejak batas dihapus; file yang masih dipakai (mis. laravel.log)
     * dipangkas: entri sebelum batas dibuang.
     *
     * @return array{0: int, 1: int} [dihapus, dipangkas]
     */
    private function cleanLogs(Carbon $cutoff): array
    {
        $deleted = $trimmed = 0;
        foreach (File::glob(storage_path('logs/*.log')) as $path) {
            if (in_array(basename($path), self::SKIP_LOGS, true)) {
                continue;
            }
            if (File::lastModified($path) < $cutoff->getTimestamp()) {
                File::delete($path) && $deleted++;
            } elseif ($this->trimLog($path, $cutoff)) {
                $trimmed++;
            }
        }

        return [$deleted, $trimmed];
    }

    /** Buang entri log sebelum $cutoff (baris diawali "[YYYY-MM-DD HH:MM:SS]"). */
    private function trimLog(string $path, Carbon $cutoff): bool
    {
        $in = fopen($path, 'rb');
        if (! $in) {
            return false;
        }

        $position = 0;
        $keepFrom = null;
        $hasOld = false;
        while (($line = fgets($in)) !== false) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', $line, $m)) {
                if (Carbon::parse($m[1])->gte($cutoff)) {
                    $keepFrom = $position;
                    break;
                }
                $hasOld = true;
            }
            $position += strlen($line);
        }

        if (! $hasOld) {
            fclose($in);

            return false;
        }

        $tmp = $path.'.tmp';
        $out = fopen($tmp, 'wb');
        if ($keepFrom !== null) {
            fseek($in, $keepFrom);
            stream_copy_to_stream($in, $out);
        }
        fclose($in);
        fclose($out);

        return rename($tmp, $path);
    }

    /** Store cache "database" tidak pernah membuang baris kedaluwarsa sendiri. */
    private function purgeExpiredCache(): int
    {
        $store = config('cache.default');
        if (config("cache.stores.{$store}.driver") !== 'database') {
            return 0;
        }

        try {
            $connection = DB::connection(config("cache.stores.{$store}.connection"));
            $count = $connection->table(config("cache.stores.{$store}.table", 'cache'))
                ->where('expiration', '<=', now()->getTimestamp())
                ->delete();
            $lockTable = config("cache.stores.{$store}.lock_table") ?: 'cache_locks';
            $connection->table($lockTable)->where('expiration', '<=', now()->getTimestamp())->delete();

            return $count;
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    private function purgeExpiredSessions(): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        try {
            return DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('last_activity', '<', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
                ->delete();
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    private function statePath(): string
    {
        return storage_path('app/mrbs-maintenance.json');
    }

    private function state(): array
    {
        $path = $this->statePath();

        return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    }

    private function saveState(array $state): void
    {
        File::put($this->statePath(), json_encode($state, JSON_PRETTY_PRINT));
    }
}
