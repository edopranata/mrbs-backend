<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'room_id', 'user_id', 'series_id', 'title', 'description', 'type', 'start_at', 'end_at',
    'participants', 'status', 'cancelled_at', 'cancelled_by', 'cancel_reason',
])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'participants' => 'integer',
            'status' => BookingStatus::class,
            'type' => BookingType::class,
        ];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @param  Builder<Booking>  $query
     */
    public function scopeConfirmed(Builder $query): void
    {
        $query->where('status', BookingStatus::Confirmed);
    }

    /**
     * Booking yang rentang waktunya beririsan dengan [$start, $end).
     *
     * @param  Builder<Booking>  $query
     */
    public function scopeOverlapping(Builder $query, Carbon $start, Carbon $end): void
    {
        $query->where('start_at', '<', $end)->where('end_at', '>', $start);
    }

    /**
     * @param  Builder<Booking>  $query
     */
    public function scopeOnDate(Builder $query, Carbon $date): void
    {
        $query->overlapping($date->copy()->startOfDay(), $date->copy()->endOfDay());
    }

    public function isCancelled(): bool
    {
        return $this->status === BookingStatus::Cancelled;
    }

    public function hasEnded(): bool
    {
        return $this->end_at->isPast();
    }
}
