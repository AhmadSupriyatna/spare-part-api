<?php

namespace App\Enums;

/**
 * Labels for MaintenanceHistoryService's read-model rows — spans multiple
 * source tables (Task, Finding, MaintenanceDecision, PartReplacementRequest,
 * PartRepair), unlike every other enum in this app which backs exactly one
 * column. Failure/Breakdown/Maintenance mirror ReplacementRequestEventType
 * 1:1 (read directly from PartReplacementRequest.event_type, never
 * re-derived) but are kept as separate cases here since this enum's job is
 * request-filtering/display for the aggregated feed, not persistence.
 */
enum MaintenanceHistoryEventType: string
{
    case Pm = 'PM';
    case Failure = 'FAILURE';
    case Breakdown = 'BREAKDOWN';
    case Maintenance = 'MAINTENANCE';
    case Repair = 'REPAIR';
    case Finding = 'FINDING';
    case Decision = 'DECISION';
}
