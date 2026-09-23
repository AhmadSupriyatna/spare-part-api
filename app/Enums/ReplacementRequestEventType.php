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

    public static function fromReplacementStrategy(PartReplacementStrategy $strategy): self
    {
        return $strategy === PartReplacementStrategy::FailureBased ? self::Failure : self::Breakdown;
    }
}
