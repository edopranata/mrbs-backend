<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingService
{
    /**
     * @param  array{room_id:int, title:string, description?:?string, start_at:string, end_at:string, participants?:?int}  $data
     */
    public function create(User $user, array $data): Booking
    {
        return DB::transaction(function () use ($user, $data) {
            $room = Room::lockForUpdate()->findOrFail($data['room_id']);
            [$start, $end] = $this->parseRange($data);

            $this->ensureBookable($user, $room, $start, $end, $data['participants'] ?? 1);

            return Booking::create([
                ...$data,
                'user_id' => $user->id,
                'start_at' => $start,
                'end_at' => $end,
                'participants' => $data['participants'] ?? 1,
                'type' => $data['type'] ?? BookingType::Internal,
                'status' => BookingStatus::Confirmed,
            ]);
        });
    }

    /**
     * Booking berulang setiap minggu pada hari & jam yang sama. Setiap minggu disimpan
     * sebagai booking tersendiri dengan series_id yang sama, sehingga cek bentrok,
     * jadwal, dan pembatalan per tanggal tetap berjalan seperti booking biasa.
     *
     * Bila ada tanggal yang tidak bisa dipesan, semua dibatalkan, kecuali $skipConflicts
     * bernilai true: tanggal tersebut dilewati dan sisanya tetap dibuat.
     *
     * @param  array<string, mixed>  $data
     * @return array{bookings: list<Booking>, skipped: list<array{date: string, reason: string}>}
     */
    public function createWeekly(User $user, array $data, int $weeks, bool $skipConflicts = false): array
    {
        return DB::transaction(function () use ($user, $data, $weeks, $skipConflicts) {
            $room = Room::lockForUpdate()->findOrFail($data['room_id']);
            [$start, $end] = $this->parseRange($data);
            $participants = $data['participants'] ?? 1;

            $occurrences = $this->occurrences($user, $room, $start, $end, $weeks, $participants);
            $failed = array_values(array_filter($occurrences, fn ($o) => ! $o['available']));
            $usable = array_values(array_filter($occurrences, fn ($o) => $o['available']));

            if ($failed && (! $skipConflicts || ! $usable)) {
                throw ValidationException::withMessages(['repeat_weeks' => $this->describeFailures($failed, count($occurrences))]);
            }

            $seriesId = (string) Str::uuid();
            $bookings = array_map(fn ($o) => Booking::create([
                ...$data,
                'user_id' => $user->id,
                'series_id' => $seriesId,
                'start_at' => $o['start'],
                'end_at' => $o['end'],
                'participants' => $participants,
                'type' => $data['type'] ?? BookingType::Internal,
                'status' => BookingStatus::Confirmed,
            ]), $usable);

            return [
                'bookings' => $bookings,
                'skipped' => array_map(fn ($o) => [
                    'date' => $o['start']->toDateString(),
                    'reason' => $o['reason'],
                ], $failed),
            ];
        });
    }

    /**
     * Daftar tanggal sebuah booking mingguan beserta status bisa/tidaknya dipesan.
     *
     * @return list<array{start: Carbon, end: Carbon, available: bool, reason: ?string}>
     */
    public function occurrences(User $user, Room $room, Carbon $start, Carbon $end, int $weeks, int $participants): array
    {
        return array_map(function (int $week) use ($user, $room, $start, $end, $participants) {
            $s = $start->copy()->addWeeks($week);
            $e = $end->copy()->addWeeks($week);

            try {
                $this->ensureBookable($user, $room, $s, $e, $participants);

                return ['start' => $s, 'end' => $e, 'available' => true, 'reason' => null];
            } catch (ValidationException $ex) {
                return ['start' => $s, 'end' => $e, 'available' => false, 'reason' => collect($ex->errors())->flatten()->first()];
            }
        }, range(0, $weeks - 1));
    }

    /**
     * @param  list<array{start: Carbon, reason: ?string}>  $failed
     */
    private function describeFailures(array $failed, int $total): string
    {
        $reasons = array_unique(array_column($failed, 'reason'));

        if (count($failed) === $total && count($reasons) === 1) {
            return $reasons[0];
        }

        $dates = implode(', ', array_map(fn ($o) => $o['start']->translatedFormat('j M'), $failed));

        return sprintf(
            '%d dari %d tanggal tidak bisa dipesan (%s). Ubah jadwal atau pilih "lewati tanggal yang bentrok".',
            count($failed), $total, $dates,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Booking $booking, array $data): Booking
    {
        return DB::transaction(function () use ($actor, $booking, $data) {
            $data = array_merge([
                'room_id' => $booking->room_id,
                'start_at' => $booking->start_at->format('Y-m-d H:i'),
                'end_at' => $booking->end_at->format('Y-m-d H:i'),
                'participants' => $booking->participants,
            ], $data);

            $room = Room::lockForUpdate()->findOrFail($data['room_id']);
            [$start, $end] = $this->parseRange($data);

            $this->ensureBookable($actor, $room, $start, $end, $data['participants'], $booking);

            $booking->update([
                ...$data,
                'start_at' => $start,
                'end_at' => $end,
            ]);

            return $booking->refresh();
        });
    }

    public function cancel(User $actor, Booking $booking, ?string $reason = null): Booking
    {
        $booking->update([
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
            'cancel_reason' => $reason,
        ]);

        return $booking->refresh();
    }

    /**
     * Batalkan booking ini beserta minggu-minggu berikutnya dalam seri yang sama.
     * Tanggal yang sudah lewat atau tidak boleh diubah oleh $actor dilewati.
     *
     * @return int jumlah booking yang dibatalkan
     */
    public function cancelFollowing(User $actor, Booking $booking, ?string $reason = null): int
    {
        $targets = Booking::query()
            ->where('series_id', $booking->series_id)
            ->confirmed()
            ->where('start_at', '>=', $booking->start_at)
            ->get()
            ->filter(fn (Booking $b) => $actor->can('cancel', $b));

        $targets->each(fn (Booking $b) => $this->cancel($actor, $b, $reason));

        return $targets->count();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function parseRange(array $data): array
    {
        return [
            Carbon::parse($data['start_at'])->seconds(0),
            Carbon::parse($data['end_at'])->seconds(0),
        ];
    }

    /**
     * Validasi aturan bisnis booking. Melempar ValidationException bila ada pelanggaran.
     */
    public function ensureBookable(
        User $actor,
        Room $room,
        Carbon $start,
        Carbon $end,
        int $participants,
        ?Booking $ignore = null,
    ): void {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        if (! $room->is_active) {
            $fail('room_id', 'Ruangan sedang tidak tersedia untuk dipesan.');
        }

        if ($end->lte($start)) {
            $fail('end_at', 'Waktu selesai harus setelah waktu mulai.');
        }

        // Booking lama yang jamnya tidak diubah tetap boleh disunting walau belum selaras interval.
        $timesChanged = ! $ignore || ! $start->eq($ignore->start_at) || ! $end->eq($ignore->end_at);
        $slot = config('mrbs.slot_minutes');
        if ($timesChanged && ($start->minute % $slot !== 0 || $end->minute % $slot !== 0)) {
            $fail('start_at', "Jam mulai dan selesai harus kelipatan {$slot} menit (mis. 09:00 atau 09:30).");
        }

        if (! $start->isSameDay($end)) {
            $fail('end_at', 'Booking harus dimulai dan selesai di hari yang sama.');
        }

        if ($start->lt(now()->seconds(0))) {
            $fail('start_at', 'Waktu mulai tidak boleh di masa lalu.');
        }

        $open = $start->copy()->setTimeFromTimeString(config('mrbs.open_time'));
        $close = $start->copy()->setTimeFromTimeString(config('mrbs.close_time'));
        if ($start->lt($open) || $end->gt($close)) {
            $fail('start_at', sprintf(
                'Ruang rapat hanya bisa dipesan antara pukul %s - %s.',
                config('mrbs.open_time'),
                config('mrbs.close_time'),
            ));
        }

        $minutes = $start->diffInMinutes($end);
        if ($minutes < config('mrbs.min_duration')) {
            $fail('end_at', 'Durasi minimal '.config('mrbs.min_duration').' menit.');
        }
        if ($minutes > config('mrbs.max_duration')) {
            $fail('end_at', 'Durasi maksimal '.(config('mrbs.max_duration') / 60).' jam.');
        }

        $maxAdvance = config('mrbs.max_advance_days');
        if (! $actor->isAdmin() && $start->gt(now()->addDays($maxAdvance)->endOfDay())) {
            $fail('start_at', "Booking hanya dapat dibuat maksimal {$maxAdvance} hari ke depan.");
        }

        if ($participants > $room->capacity) {
            $fail('participants', "Jumlah peserta melebihi kapasitas ruangan ({$room->capacity} orang).");
        }

        $conflict = Booking::query()
            ->with('user:id,name')
            ->where('room_id', $room->id)
            ->confirmed()
            ->overlapping($start, $end)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
            ->orderBy('start_at')
            ->first();

        if ($conflict) {
            $fail('start_at', sprintf(
                'Jadwal bentrok dengan "%s" (%s - %s) oleh %s.',
                $conflict->title,
                $conflict->start_at->format('H:i'),
                $conflict->end_at->format('H:i'),
                $conflict->user->name,
            ));
        }
    }
}
