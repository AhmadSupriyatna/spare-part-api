<?php

namespace App\Enums;

enum PartRepairDisposition: string
{
    case Pending = 'pending';
    case InRepair = 'in_repair';
    case Repaired = 'repaired';
    case Scrapped = 'scrapped';
}
