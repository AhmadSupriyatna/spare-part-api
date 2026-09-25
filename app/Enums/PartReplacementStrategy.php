<?php

namespace App\Enums;

/**
 * How a Part is decided to be due for replacement. Only LifeBased drives the
 * existing automatic Part Lifetime scheduling (PartUnit::percentUsed() /
 * remainingHours()) — every other case must never trigger it automatically,
 * even when estimated_lifetime_hours happens to be filled in from history.
 *
 * Deliberately just these 3 — ConditionBased/InspectionBased/OnDemand were
 * removed (Phase 3B Step 2): condition/inspection observation belongs to
 * Task Library maintenance activity, not a Part-level replacement policy,
 * and "on demand" is a Task-creation trigger, not a strategy. There is no
 * safe catch-all case left, which is intentional — see StorePartRequest,
 * which now requires this explicitly instead of defaulting to one.
 */
enum PartReplacementStrategy: string
{
    case LifeBased = 'life_based';
    case Scheduled = 'scheduled';
    case FailureBased = 'failure_based';
}
