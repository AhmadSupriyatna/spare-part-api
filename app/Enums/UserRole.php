<?php

namespace App\Enums;

enum UserRole: string
{
    case Teknisi = 'teknisi';
    case Engineer = 'engineer';
    case AdminGudang = 'admin_gudang';
    case Supervisor = 'supervisor';
    case Superadmin = 'superadmin';

    public function label(): string
    {
        return match ($this) {
            self::Teknisi => 'Teknisi',
            self::Engineer => 'Engineer',
            self::AdminGudang => 'Admin Gudang',
            self::Supervisor => 'Supervisor',
            self::Superadmin => 'Superadmin',
        };
    }
}
