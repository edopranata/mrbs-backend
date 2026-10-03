<?php

namespace App\Enums;

enum UserRole: string
{
    case SystemAdmin = 'system_admin';
    case Admin = 'admin';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::SystemAdmin => 'System Admin',
            self::Admin => 'Admin',
            self::User => 'User',
        };
    }
}
