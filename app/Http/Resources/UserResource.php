<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'department' => $this->department,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'bookings_count' => $this->whenCounted('bookings'),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
