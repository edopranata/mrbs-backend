<?php

namespace App\Enums;

enum UserRole: string
{
    case SystemAdmin = 'system_admin';
    case Admin = 'admin';
    case User = 'user';
    // Hanya melihat: Dashboard, Jadwal Ruangan, dan Semua Booking; tidak bisa membuat booking.
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::SystemAdmin => 'System Admin',
            self::Admin => 'Admin',
            self::User => 'User',
            self::Viewer => 'View Only',
        };
    }
}
