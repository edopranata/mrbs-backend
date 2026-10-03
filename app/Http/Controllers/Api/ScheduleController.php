<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    /** Rentang maksimal satu permintaan (cukup untuk tampilan bulan 6 minggu). */
    private const MAX_RANGE_DAYS = 42;

    /**
     * Jadwal ruangan aktif beserta booking terkonfirmasi.
     * Pakai `date` untuk satu hari, atau `from` + `to` untuk rentang (tampilan minggu/bulan).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'floor' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
        ], attributes: ['date' => 'tanggal', 'from' => 'tanggal awal', 'to' => 'tanggal akhir', 'floor' => 'lantai']);

        if ($request->filled('from')) {
            $from = Carbon::parse($request->input('from'))->startOfDay();
            $to = Carbon::parse($request->input('to'))->endOfDay();

            if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
                throw ValidationException::withMessages([
                    'to' => 'Rentang tanggal maksimal '.self::MAX_RANGE_DAYS.' hari.',
                ]);
            }
        } else {
            $from = $request->filled('date') ? Carbon::parse($request->input('date')) : today();
            $to = $from->copy()->endOfDay();
        }

        $rooms = Room::active()
            ->when($request->filled('floor'), fn ($q) => $q->where('floor', $request->integer('floor')))
            ->when($request->filled('room_id'), fn ($q) => $q->whereKey($request->integer('room_id')))
            ->with(['bookings' => fn ($q) => $q->confirmed()->overlapping($from, $to)->with('user')->orderBy('start_at')])
            ->orderBy('floor')
            ->orderBy('code')
            ->get();

        return response()->json([
            'date' => $from->toDateString(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'open_time' => config('mrbs.open_time'),
            'close_time' => config('mrbs.close_time'),
            'rooms' => RoomResource::collection($rooms)->resolve($request),
        ]);
    }
}
