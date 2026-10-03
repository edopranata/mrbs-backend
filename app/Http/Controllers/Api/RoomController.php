<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class RoomController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $rooms = Room::query()
            // User biasa hanya melihat ruangan aktif; admin melihat semua kecuali minta ?active=1.
            ->when(! $request->user()->isAdmin() || $request->boolean('active'), fn ($q) => $q->active())
            ->when($request->filled('floor'), fn ($q) => $q->where('floor', $request->integer('floor')))
            ->orderBy('floor')
            ->orderBy('code')
            ->get();

        return RoomResource::collection($rooms);
    }

    public function show(Room $room): RoomResource
    {
        return new RoomResource($room);
    }

    public function store(RoomRequest $request): JsonResponse
    {
        $room = Room::create($request->validated());

        return (new RoomResource($room))->response()->setStatusCode(201);
    }

    public function update(RoomRequest $request, Room $room): RoomResource
    {
        $room->update($request->validated());

        return new RoomResource($room);
    }

    public function destroy(Room $room): JsonResponse
    {
        if ($room->bookings()->exists()) {
            return response()->json([
                'message' => 'Ruangan memiliki riwayat booking dan tidak dapat dihapus. Nonaktifkan ruangan sebagai gantinya.',
            ], 422);
        }

        $room->delete();

        return response()->json(['message' => 'Ruangan berhasil dihapus.']);
    }

    /**
     * Cek ruangan mana saja yang kosong pada rentang waktu tertentu.
     * Dengan `repeat_weeks` > 1, rentang yang sama di minggu-minggu berikutnya ikut dicek.
     */
    public function availability(Request $request): JsonResponse
    {
        $request->validate([
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'participants' => ['nullable', 'integer', 'min:1'],
            'ignore_booking_id' => ['nullable', 'integer'],
            'repeat_weeks' => ['nullable', 'integer', 'min:1', 'max:'.config('mrbs.max_repeat_weeks')],
        ], attributes: ['start_at' => 'waktu mulai', 'end_at' => 'waktu selesai']);

        $start = Carbon::parse($request->input('start_at'));
        $end = Carbon::parse($request->input('end_at'));
        $participants = $request->integer('participants', 1);
        $weeks = $request->integer('repeat_weeks', 1);

        $conflicts = collect(range(0, $weeks - 1))
            ->flatMap(fn (int $week) => Booking::query()
                ->with('user:id,name')
                ->confirmed()
                ->overlapping($start->copy()->addWeeks($week), $end->copy()->addWeeks($week))
                ->when($request->filled('ignore_booking_id'), fn ($q) => $q->whereKeyNot($request->integer('ignore_booking_id')))
                ->get())
            ->sortBy('start_at')
            ->groupBy('room_id');

        $rooms = Room::active()->orderBy('floor')->orderBy('code')->get()->map(function (Room $room) use ($conflicts, $participants, $weeks) {
            $roomConflicts = $conflicts->get($room->id, collect());

            return [
                ...(new RoomResource($room))->resolve(),
                'available' => $roomConflicts->isEmpty() && $participants <= $room->capacity,
                'fits_capacity' => $participants <= $room->capacity,
                'weeks_checked' => $weeks,
                'conflict_dates' => $roomConflicts->map(fn (Booking $b) => $b->start_at->toDateString())->unique()->values(),
                'conflicts' => $roomConflicts->map(fn (Booking $b) => [
                    'id' => $b->id,
                    'title' => $b->title,
                    'date' => $b->start_at->toDateString(),
                    'start_time' => $b->start_at->format('H:i'),
                    'end_time' => $b->end_at->format('H:i'),
                    'user' => $b->user->name,
                ])->values(),
            ];
        });

        return response()->json(['data' => $rooms]);
    }
}
