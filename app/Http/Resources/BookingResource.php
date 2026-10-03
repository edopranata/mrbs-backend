<?php

namespace App\Http\Resources;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Waktu dikirim sebagai waktu lokal kantor (APP_TIMEZONE) tanpa offset,
 * misal "2026-09-26T09:00:00", sehingga frontend menampilkannya apa adanya.
 *
 * @mixin Booking
 */
class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'date' => $this->start_at->toDateString(),
            'start_at' => $this->start_at->format('Y-m-d\TH:i:s'),
            'end_at' => $this->end_at->format('Y-m-d\TH:i:s'),
            'start_time' => $this->start_at->format('H:i'),
            'end_time' => $this->end_at->format('H:i'),
            'duration_minutes' => (int) $this->start_at->diffInMinutes($this->end_at),
            'participants' => $this->participants,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_ongoing' => ! $this->isCancelled() && now()->between($this->start_at, $this->end_at),
            'has_ended' => $this->hasEnded(),
            'cancelled_at' => $this->cancelled_at?->format('Y-m-d\TH:i:s'),
            'cancel_reason' => $this->cancel_reason,
            'series_id' => $this->series_id,
            'is_recurring' => $this->series_id !== null,
            'room_id' => $this->room_id,
            'user_id' => $this->user_id,
            'room' => $this->whenLoaded('room', fn () => [
                'id' => $this->room->id,
                'code' => $this->room->code,
                'name' => $this->room->name,
                'floor' => $this->room->floor,
                'color' => $this->room->color,
            ]),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'department' => $this->user->department,
            ]),
            'cancelled_by' => $this->whenLoaded('canceller', fn () => $this->canceller?->name),
            'can' => [
                'update' => $user ? $user->can('update', $this->resource) : false,
                'cancel' => $user ? $user->can('cancel', $this->resource) : false,
            ],
            'created_at' => $this->created_at?->format('Y-m-d\TH:i:s'),
        ];
    }
}
