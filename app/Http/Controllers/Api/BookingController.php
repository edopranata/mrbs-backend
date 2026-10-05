<?php

namespace App\Http\Controllers\Api;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Room;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    /**
     * Daftar booking dengan filter. `mine=1` untuk booking milik sendiri.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(BookingStatus::class)],
            'period' => ['nullable', Rule::in(['upcoming', 'past'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'room_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $period = $request->input('period');

        $bookings = Booking::query()
            ->with(['room', 'user', 'canceller'])
            ->when($request->boolean('mine'), fn ($q) => $q->where('user_id', $request->user()->id))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('room_id'), fn ($q) => $q->where('room_id', $request->integer('room_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('start_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('start_at', '<=', $request->date('date_to')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->input('search').'%';
                $q->where(fn ($q) => $q->where('title', 'like', $term)
                    ->orWhereHas('user', fn ($q) => $q->where('name', 'like', $term)));
            })
            ->when($period === 'upcoming', fn ($q) => $q->where('end_at', '>=', now())->orderBy('start_at'))
            ->when($period === 'past', fn ($q) => $q->where('end_at', '<', now())->orderByDesc('start_at'))
            ->when(! $period, fn ($q) => $q->orderByDesc('start_at'))
            ->paginate($request->integer('per_page', 15));

        return BookingResource::collection($bookings);
    }

    public function store(BookingRequest $request): JsonResponse
    {
        Gate::authorize('create', Booking::class);

        $data = $request->safe()->except(['repeat_weeks', 'skip_conflicts']);
        $weeks = (int) $request->validated('repeat_weeks', 1);

        if ($weeks <= 1) {
            $booking = $this->bookings->create($request->user(), $data);

            return (new BookingResource($booking->load(['room', 'user'])))
                ->response()
                ->setStatusCode(201);
        }

        $result = $this->bookings->createWeekly($request->user(), $data, $weeks, $request->boolean('skip_conflicts'));
        $bookings = collect($result['bookings'])->each->load(['room', 'user']);

        return BookingResource::collection($bookings)
            ->additional(['skipped' => $result['skipped']])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Pantauan admin: booking hari ini yang sedang berlangsung dan yang akan datang.
     * Booking yang sudah selesai atau dibatalkan tidak ditampilkan.
     */
    public function today(Request $request): JsonResponse
    {
        $now = now();

        $bookings = Booking::query()
            ->confirmed()
            ->with(['room', 'user'])
            ->whereBetween('start_at', [today(), today()->endOfDay()])
            ->where('end_at', '>', $now)
            ->orderBy('start_at')
            ->get();

        [$ongoing, $upcoming] = $bookings->partition(fn (Booking $b) => $b->start_at->lte($now));

        return response()->json([
            'date' => today()->toDateString(),
            'server_time' => $now->format('Y-m-d\TH:i:s'),
            'ongoing' => BookingResource::collection($ongoing->values())->resolve($request),
            'upcoming' => BookingResource::collection($upcoming->values())->resolve($request),
        ]);
    }

    /**
     * Pratinjau tanggal-tanggal booking mingguan beserta ketersediaannya.
     */
    public function occurrences(Request $request): JsonResponse
    {
        $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date'],
            'repeat_weeks' => ['required', 'integer', 'min:1', 'max:'.config('mrbs.max_repeat_weeks')],
            'participants' => ['nullable', 'integer', 'min:1'],
        ], attributes: ['room_id' => 'ruangan', 'repeat_weeks' => 'jumlah minggu']);

        $occurrences = $this->bookings->occurrences(
            $request->user(),
            Room::findOrFail($request->integer('room_id')),
            Carbon::parse($request->input('start_at'))->seconds(0),
            Carbon::parse($request->input('end_at'))->seconds(0),
            $request->integer('repeat_weeks'),
            $request->integer('participants', 1),
        );

        return response()->json([
            'data' => array_map(fn ($o) => [
                'date' => $o['start']->toDateString(),
                'start_time' => $o['start']->format('H:i'),
                'end_time' => $o['end']->format('H:i'),
                'available' => $o['available'],
                'reason' => $o['reason'],
            ], $occurrences),
        ]);
    }

    public function show(Booking $booking): JsonResponse
    {
        $resource = new BookingResource($booking->load(['room', 'user', 'canceller']));

        if ($booking->series_id) {
            $series = Booking::where('series_id', $booking->series_id)->orderBy('start_at')->get();
            $resource->additional(['series' => [
                'total' => $series->count(),
                'position' => $series->search(fn (Booking $b) => $b->is($booking)) + 1,
                // Booking aktif mulai tanggal ini yang bisa ikut dibatalkan sekaligus.
                'following_cancellable' => $series
                    ->filter(fn (Booking $b) => $b->start_at->gte($booking->start_at) && request()->user()->can('cancel', $b))
                    ->count(),
            ]]);
        }

        return $resource->response();
    }

    public function update(BookingRequest $request, Booking $booking): BookingResource
    {
        Gate::authorize('update', $booking);

        $booking = $this->bookings->update($request->user(), $booking, $request->validated());

        return new BookingResource($booking->load(['room', 'user']));
    }

    /**
     * `scope=following` membatalkan booking ini beserta minggu-minggu berikutnya dalam seri.
     */
    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        Gate::authorize('cancel', $booking);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'scope' => ['nullable', Rule::in(['single', 'following'])],
        ]);
        $reason = $data['reason'] ?? null;

        if (($data['scope'] ?? 'single') === 'following' && $booking->series_id) {
            $count = $this->bookings->cancelFollowing($request->user(), $booking, $reason);
        } else {
            $this->bookings->cancel($request->user(), $booking, $reason);
            $count = 1;
        }

        return (new BookingResource($booking->refresh()->load(['room', 'user', 'canceller'])))
            ->additional(['cancelled_count' => $count])
            ->response();
    }

    /**
     * Hapus permanen (khusus admin).
     */
    public function destroy(Booking $booking): JsonResponse
    {
        Gate::authorize('delete', $booking);

        $booking->delete();

        return response()->json(['message' => 'Booking berhasil dihapus.']);
    }
}
