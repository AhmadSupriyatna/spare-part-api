<?php

namespace App\Enums;

/**
 * How a Part is decided to be due for replacement. Only LifeBased drives the
 * existing automatic Part Lifetime scheduling (PartUnit::percentUsed() /
 * remainingHours()) — every other case must never trigger it automatically,
 * even when estimated_lifetime_hours happens to be filled in from history.
 */
enum PartReplacementStrategy: string
{
    case LifeBased = 'life_based';
    case FailureBased = 'failure_based';
    case ConditionBased = 'condition_based';
    case Scheduled = 'scheduled';
    case InspectionBased = 'inspection_based';
    case OnDemand = 'on_demand';
}
