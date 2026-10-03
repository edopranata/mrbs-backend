<?php

namespace App\Http\Resources;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Room */
class RoomResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'floor' => $this->floor,
            'capacity' => $this->capacity,
            'facilities' => $this->facilities ?? [],
            'description' => $this->description,
            'color' => $this->color,
            'is_active' => $this->is_active,
            'bookings' => BookingResource::collection($this->whenLoaded('bookings')),
        ];
    }
}
