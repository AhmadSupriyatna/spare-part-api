<?php

namespace App\Enums;

enum PartUnitStatus: string
{
    case InService = 'in_service';
    case PendingRepair = 'pending_repair';
    case InRepair = 'in_repair';
    case Available = 'available';
    case Scrapped = 'scrapped';
}
