<?php

namespace App\Enums;

enum UserRole: string
{
    case Teknisi = 'teknisi';
    case Engineer = 'engineer';
    case AdminSparePart = 'admin_spare_part';
    case Supervisor = 'supervisor';
    case Superadmin = 'superadmin';

    public function label(): string
    {
        return match ($this) {
            self::Teknisi => 'Teknisi',
            self::Engineer => 'Engineer',
            self::AdminSparePart => 'Admin Spare Part',
            self::Supervisor => 'Supervisor',
            self::Superadmin => 'Superadmin',
        };
    }
}
