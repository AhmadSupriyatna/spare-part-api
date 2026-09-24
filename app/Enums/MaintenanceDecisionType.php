<?php

namespace App\Enums;

/**
 * The formal disposition taken after a Finding is reviewed. Physical
 * execution (Task creation, PartReplacementRequest creation, etc.) is
 * deliberately NOT part of this enum's concern — see MaintenanceDecision.
 */
enum MaintenanceDecisionType: string
{
    case NoAction = 'no_action';
    case Monitor = 'monitor';
    case ScheduleMaintenance = 'schedule_maintenance';
    case ReplacePart = 'replace_part';
    case RepairPart = 'repair_part';
    case Escalate = 'escalate';
}
