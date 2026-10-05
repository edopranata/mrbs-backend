<?php

namespace App\Services;

use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Memindahkan booking dari MRBS lama (mrbs-code, tabel entry/room/area/users) ke aplikasi ini.
 *
 * Pemetaan pembuat booking (create_by → username baru) dan ruangan (room lama → kode ruangan baru)
 * disimpan sebagai CSV yang bisa disunting. Setiap booking hasil impor menyimpan `legacy_id`, jadi
 * impor aman dijalankan berulang: entri yang sudah pernah diimpor dilewati.
 */
class LegacyBookingImporter
{
    public const USER_MAP = 'users.csv';

    public const ROOM_MAP = 'rooms.csv';

    private const USER_COLUMNS = ['old_username', 'old_display_name', 'bookings', 'new_username'];

    private const ROOM_COLUMNS = ['old_room_id', 'old_room_name', 'old_area', 'bookings', 'new_room_code'];

    /** Status MRBS lama: bit 0x04 = tentative (belum dikonfirmasi). */
    private const STATUS_TENTATIVE = 0x04;

    public function __construct(private readonly string $connection = 'legacy') {}

    private function legacy(): Connection
    {
        return DB::connection($this->connection);
    }

    // ------------------------------------------------------------------ Data MRBS lama

    /** Ruangan lama beserta area, zona waktu, dan jumlah booking. */
    public function legacyRooms(): Collection
    {
        // Tanpa alias tabel: prefix koneksi (mis. "mrbs_") tidak diterapkan pada ekspresi mentah.
        $counts = $this->legacy()->table('entry')->groupBy('room_id')->pluck(DB::raw('COUNT(*)'), 'room_id');
        $areas = $this->legacy()->table('area')->get(['id', 'area_name', 'timezone'])->keyBy('id');

        return $this->legacy()->table('room')->orderBy('id')->get(['id', 'room_name', 'area_id'])
            ->map(fn ($r) => (object) [
                'id' => (int) $r->id,
                'room_name' => (string) $r->room_name,
                'area_name' => (string) ($areas[$r->area_id]->area_name ?? ''),
                'timezone' => $areas[$r->area_id]->timezone ?? null,
                'bookings' => (int) ($counts[$r->id] ?? 0),
            ]);
    }

    /** Pembuat booking (create_by) beserta nama tampilan di MRBS lama dan jumlah booking. */
    public function legacyCreators(): Collection
    {
        $names = $this->legacy()->table('users')->pluck('display_name', 'name');

        return $this->legacy()->table('entry')
            ->groupBy('create_by')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get(['create_by', DB::raw('COUNT(*) as bookings')])
            ->map(fn ($row) => (object) [
                'username' => (string) $row->create_by,
                'display_name' => (string) ($names[$row->create_by] ?? ''),
                'bookings' => (int) $row->bookings,
            ]);
    }

    // ------------------------------------------------------------------ File pemetaan

    /**
     * Buat/perbarui users.csv & rooms.csv. Nilai pemetaan yang sudah diisi tidak ditimpa; baris baru
     * diisi otomatis bila cocok (username sama, atau nama ruangan sama tanpa akhiran "[Lt n]").
     *
     * @return array{users: int, rooms: int}
     */
    public function makeMaps(string $dir): array
    {
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Folder {$dir} tidak bisa dibuat.");
        }

        $existingUsers = $this->readCsv("{$dir}/".self::USER_MAP, 'old_username', 'new_username');
        $newUsernames = User::pluck('username')->map(fn ($u) => strtolower($u))->flip();
        $users = $this->legacyCreators()->map(fn ($c) => [
            $c->username,
            $c->display_name,
            $c->bookings,
            $existingUsers[$c->username] ?? (isset($newUsernames[strtolower($c->username)]) ? strtolower($c->username) : ''),
        ]);
        $this->writeCsv("{$dir}/".self::USER_MAP, self::USER_COLUMNS, $users);

        $existingRooms = $this->readCsv("{$dir}/".self::ROOM_MAP, 'old_room_id', 'new_room_code');
        $newRooms = Room::all();
        $rooms = $this->legacyRooms()->map(fn ($r) => [
            $r->id,
            $r->room_name,
            $r->area_name,
            (int) $r->bookings,
            $existingRooms[(string) $r->id] ?? ($this->guessRoom($r->room_name, $newRooms)?->code ?? ''),
        ]);
        $this->writeCsv("{$dir}/".self::ROOM_MAP, self::ROOM_COLUMNS, $rooms);

        return ['users' => $users->count(), 'rooms' => $rooms->count()];
    }

    /** "BRILIAN ROOM [Lt 3]" → ruangan baru bernama "BRILIAN ROOM" (tidak peka huruf besar/kecil). */
    private function guessRoom(string $legacyName, Collection $rooms): ?Room
    {
        $name = strtolower(trim(preg_replace('/\s*\[[^\]]*\]\s*$/', '', $legacyName)));

        return $rooms->first(fn (Room $room) => strtolower($room->name) === $name || strtolower($room->code) === $name);
    }

    // ------------------------------------------------------------------ Impor

    /**
     * Impor booking dari MRBS lama. Dengan `sync`, booking yang sudah pernah diimpor juga
     * diperbarui bila diubah di MRBS lama (kolom `timestamp`), dan booking mendatang yang dihapus
     * di MRBS lama dibatalkan di sini. Riwayat (booking yang sudah lewat) tidak pernah dihapus.
     *
     * @param  array{dry_run?: bool, fallback_user?: ?string, allow_conflicts?: bool, sync?: bool, max_cancellations?: int}  $options
     * @return array<string, mixed> ringkasan untuk ditampilkan
     */
    public function import(string $dir, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $allowConflicts = (bool) ($options['allow_conflicts'] ?? false);
        $sync = (bool) ($options['sync'] ?? false);

        foreach ([self::USER_MAP, self::ROOM_MAP] as $file) {
            if (! is_file("{$dir}/{$file}")) {
                throw new RuntimeException("File pemetaan {$dir}/{$file} belum ada. Jalankan dulu dengan --make-maps.");
            }
        }

        // Pemetaan user: username lama → id user baru.
        $usersByName = User::all()->keyBy(fn (User $u) => strtolower($u->username));
        $unknownTargets = [];
        $userMap = [];
        foreach ($this->readCsv("{$dir}/".self::USER_MAP, 'old_username', 'new_username') as $old => $new) {
            $new = strtolower(trim($new));
            if ($new === '') {
                continue;
            }
            if (! isset($usersByName[$new])) {
                $unknownTargets[$old] = $new;

                continue;
            }
            $userMap[$old] = $usersByName[$new]->id;
        }

        $fallbackId = null;
        if (filled($options['fallback_user'] ?? null)) {
            $fallback = $usersByName[strtolower($options['fallback_user'])] ?? null;
            if (! $fallback) {
                throw new RuntimeException("User cadangan \"{$options['fallback_user']}\" tidak ditemukan di aplikasi ini.");
            }
            $fallbackId = $fallback->id;
        }

        // Pemetaan ruangan: id ruangan lama → id ruangan baru (via kode ruangan).
        $roomsByCode = Room::all()->keyBy(fn (Room $r) => strtolower($r->code));
        $roomMap = [];
        $unknownRooms = [];
        foreach ($this->readCsv("{$dir}/".self::ROOM_MAP, 'old_room_id', 'new_room_code') as $oldId => $code) {
            $code = strtolower(trim($code));
            if ($code === '') {
                continue;
            }
            if (! isset($roomsByCode[$code])) {
                $unknownRooms[$oldId] = $code;

                continue;
            }
            $roomMap[(int) $oldId] = $roomsByCode[$code]->id;
        }

        $legacyRooms = $this->legacyRooms()->keyBy('id');
        $timezones = $legacyRooms->map(fn ($r) => $r->timezone ?: config('app.timezone'));
        // Booking hasil impor sebelumnya: legacy_id → id, versi (timestamp MRBS lama), status.
        $imported = DB::table('bookings')->whereNotNull('legacy_id')
            ->get(['id', 'legacy_id', 'legacy_modified_at', 'status'])
            ->keyBy('legacy_id');

        // Booking yang dibuat di aplikasi ini per ruangan, untuk cek bentrok.
        $existing = DB::table('bookings')
            ->whereNull('legacy_id')
            ->where('status', 'confirmed')
            ->get(['id', 'room_id', 'start_at', 'end_at', 'title'])
            ->groupBy('room_id');

        $report = [
            'total' => 0, 'already' => 0, 'importable' => 0, 'imported' => 0,
            'changed' => 0, 'updated' => 0, 'deleted_upstream' => 0, 'cancelled' => 0,
            'unmapped_users' => [], 'unmapped_rooms' => [], 'conflicts' => [], 'conflict_count' => 0,
            'tentative' => 0, 'long' => 0, 'recurring' => 0, 'with_wa' => 0, 'fallback' => 0,
            'unknown_user_targets' => $unknownTargets, 'unknown_room_targets' => $unknownRooms,
        ];
        $rows = [];
        $updates = [];
        $backfill = [];
        $seenLegacyIds = [];

        $this->legacy()->table('entry')->orderBy('id')->chunk(1000, function ($entries) use (
            &$report, &$rows, &$updates, &$backfill, &$seenLegacyIds, $userMap, $fallbackId, $roomMap, $legacyRooms,
            $timezones, $imported, $existing, $allowConflicts, $sync
        ) {
            foreach ($entries as $e) {
                $report['total']++;
                $seenLegacyIds[(int) $e->id] = true;
                $modifiedAt = $e->timestamp ? Carbon::parse($e->timestamp)->format('Y-m-d H:i:s') : null;
                $previous = $imported[$e->id] ?? null;

                if ($previous && $sync && $previous->legacy_modified_at === null) {
                    // Diimpor sebelum kolom versi ada: cukup catat versinya, isi booking tidak diubah.
                    $report['already']++;
                    if ($modifiedAt !== null) {
                        $backfill[$previous->id] = $modifiedAt;
                    }

                    continue;
                }

                if ($previous) {
                    // Sudah pernah diimpor: hanya diproses ulang saat sinkronisasi dan bila berubah di MRBS lama.
                    // Tidak berubah → tidak disentuh (termasuk bila dibatalkan di aplikasi ini).
                    $unchanged = $modifiedAt === null || (string) $previous->legacy_modified_at === $modifiedAt;
                    if (! $sync || $unchanged) {
                        $report['already']++;

                        continue;
                    }
                }

                $roomId = $roomMap[(int) $e->room_id] ?? null;
                if (! $roomId) {
                    $name = $legacyRooms[$e->room_id]->room_name ?? "room #{$e->room_id}";
                    $report['unmapped_rooms'][$name] = ($report['unmapped_rooms'][$name] ?? 0) + 1;

                    continue;
                }

                $userId = $userMap[(string) $e->create_by] ?? null;
                if (! $userId && $fallbackId) {
                    $userId = $fallbackId;
                    $report['fallback']++;
                }
                if (! $userId) {
                    $report['unmapped_users'][$e->create_by] = ($report['unmapped_users'][$e->create_by] ?? 0) + 1;

                    continue;
                }

                $tz = $timezones[$e->room_id] ?? config('app.timezone');
                $start = Carbon::createFromTimestamp((int) $e->start_time, $tz)->setTimezone(config('app.timezone'));
                $end = Carbon::createFromTimestamp((int) $e->end_time, $tz)->setTimezone(config('app.timezone'));

                $conflict = collect($existing[$roomId] ?? [])->first(
                    fn ($b) => Carbon::parse($b->start_at)->lt($end) && Carbon::parse($b->end_at)->gt($start)
                );
                if ($conflict) {
                    $report['conflict_count']++;
                    if (count($report['conflicts']) < 15) {
                        $report['conflicts'][] = sprintf(
                            '#%d %s %s–%s "%s" bentrok dengan #%d "%s"',
                            $e->id, $start->format('Y-m-d'), $start->format('H:i'), $end->format('H:i'),
                            $e->name, $conflict->id, $conflict->title,
                        );
                    }
                    if (! $allowConflicts) {
                        continue;
                    }
                }

                if (((int) $e->status) & self::STATUS_TENTATIVE) {
                    $report['tentative']++;
                }
                if ($end->diffInMinutes($start, true) >= 24 * 60) {
                    $report['long']++;
                }
                if ((int) $e->repeat_id > 0) {
                    $report['recurring']++;
                }

                $wa = trim((string) ($e->No_WA_PIC ?? ''));
                if ($wa !== '') {
                    $report['with_wa']++;
                }
                $description = trim(implode("\n\n", array_filter([trim((string) $e->description), $wa !== '' ? "PIC (WA): {$wa}" : null])));

                $fields = [
                    'room_id' => $roomId,
                    'user_id' => $userId,
                    // Seri berulang lama → series_id tetap (deterministik) agar impor ulang konsisten.
                    'series_id' => (int) $e->repeat_id > 0 ? Uuid::uuid5(Uuid::NAMESPACE_URL, "mrbs-legacy:repeat:{$e->repeat_id}")->toString() : null,
                    'title' => trim((string) $e->name) !== '' ? mb_substr(trim((string) $e->name), 0, 255) : '(tanpa judul)',
                    'description' => $description !== '' ? $description : null,
                    'type' => strtoupper((string) $e->type) === 'E' ? 'external' : 'internal',
                    'start_at' => $start->format('Y-m-d H:i:s'),
                    'end_at' => $end->format('Y-m-d H:i:s'),
                    // Tentative di MRBS lama diimpor sebagai booking biasa (sesuai keputusan migrasi).
                    'status' => 'confirmed',
                    'cancelled_at' => null,
                    'cancelled_by' => null,
                    'cancel_reason' => null,
                    'legacy_modified_at' => $modifiedAt,
                ];

                if ($previous) {
                    $report['changed']++;
                    $updates[$previous->id] = $fields + ['updated_at' => now()->format('Y-m-d H:i:s')];

                    continue;
                }

                $createdAt = $modifiedAt ?? now()->format('Y-m-d H:i:s');
                $report['importable']++;
                $rows[] = ['legacy_id' => $e->id] + $fields + [
                    'participants' => 1,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }
        });

        // Dihapus di MRBS lama: booking yang belum selesai dibatalkan; riwayat dibiarkan.
        $deletedIds = [];
        $report['cancel_blocked'] = null;
        if ($sync && $report['total'] === 0) {
            // Pengaman: database lama kosong (mis. salah nama database) jangan sampai membatalkan semuanya.
            $report['cancel_blocked'] = 'Database MRBS lama tidak berisi booking; pembatalan otomatis dilewati.';
        } elseif ($sync) {
            $deletedIds = DB::table('bookings')
                ->whereNotNull('legacy_id')
                ->where('status', 'confirmed')
                ->where('end_at', '>=', now()->format('Y-m-d H:i:s'))
                ->get(['id', 'legacy_id'])
                ->reject(fn ($b) => isset($seenLegacyIds[(int) $b->legacy_id]))
                ->pluck('id')
                ->all();
            $report['deleted_upstream'] = count($deletedIds);
            $limit = (int) ($options['max_cancellations'] ?? 50);
            if (count($deletedIds) > $limit) {
                // Pengaman: terlalu banyak yang hilang sekaligus biasanya tanda masalah, bukan penghapusan sungguhan.
                $report['cancel_blocked'] = count($deletedIds)." booking hilang dari MRBS lama sekaligus (batas {$limit}); "
                    .'pembatalan otomatis dilewati. Periksa database lama, lalu jalankan dengan --max-cancellations bila memang benar.';
                $deletedIds = [];
            }
        }

        if (! $dryRun && $backfill) {
            DB::transaction(function () use ($backfill) {
                foreach ($backfill as $id => $modifiedAt) {
                    DB::table('bookings')->where('id', $id)->update(['legacy_modified_at' => $modifiedAt]);
                }
            });
        }

        if (! $dryRun && ($rows || $updates || $deletedIds)) {
            DB::transaction(function () use ($rows, $updates, $deletedIds, &$report) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('bookings')->insert($chunk);
                    $report['imported'] += count($chunk);
                }
                foreach ($updates as $id => $fields) {
                    DB::table('bookings')->where('id', $id)->update($fields);
                    $report['updated']++;
                }
                foreach (array_chunk($deletedIds, 500) as $chunk) {
                    $report['cancelled'] += DB::table('bookings')->whereIn('id', $chunk)->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now()->format('Y-m-d H:i:s'),
                        'cancel_reason' => 'Dihapus di MRBS lama',
                        'updated_at' => now()->format('Y-m-d H:i:s'),
                    ]);
                }
            });
        }

        arsort($report['unmapped_users']);

        return $report;
    }

    // ------------------------------------------------------------------ CSV

    /** @return array<string, string> kolom kunci → kolom nilai */
    private function readCsv(string $path, string $keyColumn, string $valueColumn): array
    {
        if (! is_file($path)) {
            return [];
        }
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '');
        $map = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            if ($row === [null] || $header === false || count($row) !== count($header)) {
                continue;
            }
            $assoc = array_combine($header, $row);
            $map[(string) $assoc[$keyColumn]] = (string) ($assoc[$valueColumn] ?? '');
        }
        fclose($handle);

        return $map;
    }

    private function writeCsv(string $path, array $header, Collection $rows): void
    {
        $handle = fopen($path, 'w');
        fputcsv($handle, $header, escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }
        fclose($handle);
        chmod($path, 0600);
    }
}
