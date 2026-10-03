<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BookingPolicy
{
    /**
     * Admin boleh mengubah booking siapa pun; user hanya booking miliknya.
     * Booking yang sudah dibatalkan atau sudah selesai tidak bisa diubah.
     */
    public function update(User $user, Booking $booking): Response
    {
        if ($booking->isCancelled()) {
            return Response::deny('Booking sudah dibatalkan.');
        }

        if ($booking->hasEnded()) {
            return Response::deny('Booking yang sudah selesai tidak dapat diubah.');
        }

        return $user->isAdmin() || $booking->user_id === $user->id
            ? Response::allow()
            : Response::deny('Anda hanya dapat mengubah booking milik Anda sendiri.');
    }

    public function cancel(User $user, Booking $booking): Response
    {
        return $this->update($user, $booking);
    }
}
