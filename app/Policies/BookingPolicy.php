<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BookingPolicy
{
    public const VIEW_ONLY = 'Akun View Only hanya dapat melihat jadwal dan booking.';

    public const LEGACY_LOCKED = 'Booking ini berasal dari MRBS lama. Selama masa transisi, ubah atau batalkan di MRBS lama.';

    /** Membuat booking: semua level kecuali View Only. */
    public function create(User $user): Response
    {
        return $user->isViewer() ? Response::deny(self::VIEW_ONLY) : Response::allow();
    }

    /**
     * Admin boleh mengubah booking siapa pun; user hanya booking miliknya.
     * Booking yang sudah dibatalkan atau sudah selesai tidak bisa diubah.
     */
    public function update(User $user, Booking $booking): Response
    {
        if ($user->isViewer()) {
            return Response::deny(self::VIEW_ONLY);
        }

        if ($booking->isLegacyLocked()) {
            return Response::deny(self::LEGACY_LOCKED);
        }

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

    /** Hapus permanen: khusus admin, dan bukan booking dari MRBS lama selama masa transisi. */
    public function delete(User $user, Booking $booking): Response
    {
        if ($booking->isLegacyLocked()) {
            return Response::deny(self::LEGACY_LOCKED);
        }

        return $user->isAdmin() ? Response::allow() : Response::deny('Hanya admin yang dapat menghapus booking.');
    }
}
