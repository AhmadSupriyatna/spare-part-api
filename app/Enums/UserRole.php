<?php

namespace App\Enums;

enum UserRole: string
{
    case Engineer = 'engineer';
    case AdminSparePart = 'admin_spare_part';
    case Supervisor = 'supervisor';
    case Superadmin = 'superadmin';

    public function label(): string
    {
        return match ($this) {
            self::Engineer => 'Engineer',
            self::AdminSparePart => 'Admin Spare Part',
            self::Supervisor => 'Supervisor/Manager',
            self::Superadmin => 'Super Admin',
        };
    }

    /**
     * Every role except Superadmin is confined to whichever branches are
     * actually assigned to the account — see EnsureBranchAccess.
     */
    public function isBranchScoped(): bool
    {
        return $this !== self::Superadmin;
    }
}
