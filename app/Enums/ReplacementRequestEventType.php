<?php

namespace App\Enums;

/**
 * What a Part QR scan report actually is, decided from the Part's own
 * replacement_strategy at the moment the request is created — never from
 * client input. QR Scan is one door for the operator; this is the fork
 * behind it that Phase 2 (Failure Management vs Breakdown Management) will
 * branch on. FailureBased parts are expected to be replaced on failure, so
 * that report is the normal, planned-for event; every other strategy
 * expects a report only when something forced a replacement before its
 * own plan (lifetime, schedule, inspection, condition, or "on demand")
 * played out — that's a Breakdown.
 */
enum ReplacementRequestEventType: string
{
    case Failure = 'failure';
    case Breakdown = 'breakdown';

    /**
     * A replacement that traces back to a Finding -> MaintenanceDecision
     * (REPLACE_PART) — planned/reviewed, never an operational breakdown. Only
     * ever set by MaintenanceDecisionService, never by the QR flow.
     */
    case Maintenance = 'maintenance';

    public static function fromReplacementStrategy(PartReplacementStrategy $strategy): self
    {
        return $strategy === PartReplacementStrategy::FailureBased ? self::Failure : self::Breakdown;
    }

    /**
     * Feeds installation/stock-ledger notes and the Approval "Asal" badge —
     * kept as an explicit match rather than a two-way ternary, since a
     * Failure-or-else fallback would silently mislabel Maintenance as
     * Breakdown.
     */
    public function label(): string
    {
        return match ($this) {
            self::Failure => 'Failure',
            self::Breakdown => 'Breakdown',
            self::Maintenance => 'Maintenance',
        };
    }
}
