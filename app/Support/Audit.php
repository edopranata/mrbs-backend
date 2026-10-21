<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pencatat audit log. Dipanggil dari controller setelah aksi berhasil; kegagalan mencatat tidak
 * boleh menggagalkan aksi pengguna.
 */
class Audit
{
    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  kolom => [sebelum, sesudah]
     * @param  array<string, mixed>  $meta
     */
    public static function log(
        string $action,
        string $description,
        ?Model $subject = null,
        array $changes = [],
        array $meta = [],
        ?User $actor = null,
        ?string $actorName = null,
        bool $fromRequest = true,
    ): ?AuditLog {
        $actor ??= $fromRequest ? request()->user() : null;
        $request = $fromRequest ? request() : null;

        try {
            return AuditLog::create([
                'user_id' => $actor?->id,
                'user_name' => $actorName ?? ($actor ? "{$actor->name} ({$actor->username})" : null),
                'action' => $action,
                'subject_type' => $subject ? Str::snake(class_basename($subject)) : null,
                'subject_id' => $subject?->getKey(),
                'description' => Str::limit($description, 497),
                'changes' => $changes ?: null,
                'meta' => array_filter($meta, fn ($v) => $v !== null && $v !== '' && $v !== []) ?: null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 252) : null,
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Aktivitas sistem (tanpa pelaku & tanpa request), mis. sinkronisasi terjadwal. */
    public static function system(string $action, string $description, array $meta = []): ?AuditLog
    {
        return self::log($action, $description, meta: $meta, actorName: 'Sistem', fromRequest: false);
    }

    /**
     * Kolom yang berubah antara dua snapshot. Snapshot kosong di satu sisi = data baru / dihapus.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach (array_keys($before + $after) as $key) {
            $old = self::plain($before[$key] ?? null);
            $new = self::plain($after[$key] ?? null);
            if ($old !== $new) {
                $changes[$key] = [$old, $new];
            }
        }

        return $changes;
    }

    /** Nilai siap disimpan & dibandingkan: tanggal, enum, dan array dijadikan teks. */
    public static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i'),
            $value instanceof BackedEnum => method_exists($value, 'label') ? $value->label() : $value->value,
            is_array($value) => implode(', ', array_map(fn ($v) => (string) self::plain($v), $value)),
            is_bool($value) => $value ? 'Ya' : 'Tidak',
            $value === '' => null,
            default => $value,
        };
    }

    /** Snapshot booking untuk dibandingkan sebelum/sesudah diubah. */
    public static function bookingSnapshot(Booking $booking): array
    {
        $booking->loadMissing('room');

        return [
            'title' => $booking->title,
            'description' => $booking->description,
            'type' => $booking->type,
            'room' => $booking->room?->name,
            'start_at' => $booking->start_at,
            'end_at' => $booking->end_at,
            'participants' => $booking->participants,
        ];
    }

    /** "Rapat Tim" — BRILIAN ROOM, 21 Okt 2026 09:00–10:00 */
    public static function bookingLabel(Booking $booking): string
    {
        $booking->loadMissing('room');

        return sprintf(
            '"%s" — %s, %s %s–%s',
            $booking->title,
            $booking->room?->name ?? 'ruangan dihapus',
            $booking->start_at->translatedFormat('j M Y'),
            $booking->start_at->format('H:i'),
            $booking->end_at->format('H:i'),
        );
    }

    public static function userLabel(User $user): string
    {
        return "{$user->name} ({$user->username})";
    }
}
