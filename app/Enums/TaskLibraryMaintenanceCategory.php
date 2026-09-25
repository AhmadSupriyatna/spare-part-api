<?php

namespace App\Enums;

/**
 * What KIND of planned maintenance activity a Task Library recipe
 * represents — deliberately separate from both PartReplacementStrategy (the
 * Part's own long-term replacement policy) and ScheduleType (how/when an
 * actual Task gets created from this recipe: calendar/runtime/none). A
 * recipe's category never implies a schedule_type or vice versa; see the
 * Task Library validation rules for how loosely the two are allowed to mix.
 */
enum TaskLibraryMaintenanceCategory: string
{
    /** Intended for lifetime-driven replacement (Part Lifetime). Not yet wired to PartLifetimeService — see Phase 3B. */
    case LifeTime = 'life_time';

    /** Intended for calendar/periodic scheduled maintenance work. */
    case ScheduledMaintenance = 'scheduled_maintenance';

    /**
     * Intended to inspect/check equipment or parts — does NOT itself imply
     * a replacement. Whether an inspection leads to a replacement is a
     * separate, later decision (Finding/MaintenanceDecision), not something
     * this category encodes.
     */
    case Inspection = 'inspection';
}
