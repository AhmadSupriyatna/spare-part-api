<?php

namespace App\Services;

use App\Enums\FindingResult;
use App\Enums\FindingSeverity;
use App\Enums\FindingSourceType;
use App\Models\Finding;
use App\Models\TaskPartCheck;
use App\Models\User;

/**
 * Creates a Finding whose identity (source, equipment, part) is always
 * derived from an already-authoritative record — never from raw client
 * input (see TaskService::completeChecklist(), the only caller as of Step 3:
 * $partCheck and $equipmentId are the same validated TaskPartCheck row and
 * Task equipment that Phase 1's checklist hardening already resolved).
 *
 * This service knows nothing about MaintenanceDecision, scheduling,
 * replacement, stock, or lifecycle — that starts at Step 4.
 */
class MaintenanceFindingService
{
    /**
     * @param  array{description: string, result: string, severity?: string|null, recommendation?: string|null}  $payload
     *                                                                                                                     Already validated by CompleteTaskRequest (result/severity are real enum
     *                                                                                                                     values, description present, severity present unless result=pass) —
     *                                                                                                                     this method trusts that contract rather than re-validating format.
     */
    public function createFromTaskPartCheck(TaskPartCheck $partCheck, int $equipmentId, array $payload, ?User $reportedBy): Finding
    {
        return Finding::create([
            'source_type' => FindingSourceType::TaskPartCheck,
            'source_id' => $partCheck->id,
            'equipment_id' => $equipmentId,
            'part_id' => $partCheck->part_id,
            'description' => $payload['description'],
            'result' => FindingResult::from($payload['result']),
            'severity' => isset($payload['severity']) ? FindingSeverity::from($payload['severity']) : null,
            'recommendation' => $payload['recommendation'] ?? null,
            'reported_by' => $reportedBy?->id,
            'reported_at' => now(),
        ]);
    }
}
