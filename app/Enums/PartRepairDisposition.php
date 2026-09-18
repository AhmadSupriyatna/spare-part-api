<?php

namespace App\Enums;

enum PartRepairDisposition: string
{
    case Pending = 'pending';
    case InRepair = 'in_repair';
    case Repaired = 'repaired';
    case Scrapped = 'scrapped';

    /**
     * Progression order for the Repair Part board's 3 columns (Scrapped is
     * terminal, off to the side rather than "further along") — used to tell
     * a normal forward move (e.g. pending -> in_repair) apart from a
     * correction that reverts a mistaken status (e.g. repaired -> pending,
     * or un-scrapping), which only Superadmin is allowed to do.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::InRepair => 1,
            self::Repaired => 2,
            self::Scrapped => 3,
        };
    }

    public function isBackwardFrom(self $current): bool
    {
        if ($this === $current) {
            return false;
        }

        if ($current === self::Scrapped) {
            return true;
        }

        return $this->rank() < $current->rank();
    }
}
