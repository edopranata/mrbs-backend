<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $now = now();

        $todayBookings = Booking::confirmed()
            ->onDate(today())
            ->with(['room', 'user'])
            ->orderBy('start_at')
            ->get();

        $ongoing = $todayBookings->filter(fn (Booking $b) => $now->between($b->start_at, $b->end_at));

        $myUpcoming = Booking::confirmed()
            ->where('user_id', $user->id)
            ->where('end_at', '>=', $now)
            ->with(['room', 'user'])
            ->orderBy('start_at')
            ->limit(5)
            ->get();

        $totalRooms = Room::active()->count();

        $data = [
            'stats' => [
                'rooms_total' => $totalRooms,
                'rooms_in_use' => $ongoing->pluck('room_id')->unique()->count(),
                'bookings_today' => $todayBookings->count(),
                'my_upcoming' => Booking::confirmed()->where('user_id', $user->id)->where('end_at', '>=', $now)->count(),
            ],
            'ongoing' => BookingResource::collection($ongoing->values())->resolve($request),
            'today' => BookingResource::collection($todayBookings)->resolve($request),
            'my_upcoming' => BookingResource::collection($myUpcoming)->resolve($request),
        ];

        if ($user->isAdmin()) {
            $monthStart = $now->copy()->startOfMonth();
            $monthEnd = $now->copy()->endOfMonth();

            $data['admin'] = [
                'users_total' => User::count(),
                'users_active' => User::where('is_active', true)->count(),
                'bookings_this_month' => Booking::confirmed()->whereBetween('start_at', [$monthStart, $monthEnd])->count(),
                'cancelled_this_month' => Booking::where('status', 'cancelled')->whereBetween('start_at', [$monthStart, $monthEnd])->count(),
                'room_usage' => Room::orderBy('floor')->orderBy('code')
                    ->withCount(['bookings' => fn ($q) => $q->confirmed()->whereBetween('start_at', [$monthStart, $monthEnd])])
                    ->get()
                    ->map(fn (Room $room) => [
                        'id' => $room->id,
                        'name' => $room->name,
                        'floor' => $room->floor,
                        'color' => $room->color,
                        'bookings_count' => $room->bookings_count,
                    ]),
            ];
        }

        return response()->json($data);
    }
}
