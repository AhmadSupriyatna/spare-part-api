<?php

namespace App\Services;

use App\Enums\FindingSourceType;
use App\Enums\MaintenanceDecisionType;
use App\Enums\ReplacementRequestEventType;
use App\Enums\ReplacementRequestStatus;
use App\Exceptions\FindingAlreadyDecidedException;
use App\Exceptions\FindingMissingPartException;
use App\Exceptions\InvalidFollowUpQuantityException;
use App\Exceptions\TaskLibraryEquipmentMismatchException;
use App\Models\Finding;
use App\Models\MaintenanceDecision;
use App\Models\PartInstallation;
use App\Models\PartReplacementRequest;
use App\Models\Task;
use App\Models\TaskLibrary;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns a formal disposition on a Finding into, at most, one follow-up
 * record — never physical replacement, never a Task re-open, never a second
 * scheduling/replacement pipeline (see the Phase 2 Step 4 architecture lock).
 * A follow-up Task always goes through PmSchedulingService (schedule() for a
 * Task Library pick, scheduleAdHoc() otherwise); REPLACE_PART only ever
 * creates a Pending PartReplacementRequest (event_type=Maintenance) for the
 * existing Approval Board / PartReplacementService::approve() to execute
 * later. This service never touches PartLifecycleService, StockMovementService,
 * or approves anything itself.
 */
class MaintenanceDecisionService
{
    public function __construct(private readonly PmSchedulingService $scheduling) {}

    /**
     * Already validated by StoreMaintenanceDecisionRequest (decision type is
     * a real enum value, follow-up shape matches the decision type, Task
     * Library ownership and replacement quantity already checked there) —
     * this method trusts that contract for shape, but still re-checks the
     * invariants that depend on the Finding's own row lock (already
     * decided?) or that mirror how the existing Approval flow re-validates
     * at execution time regardless of what was checked at request time.
     *
     * @param  array{decision: string, notes?: ?string, follow_up_task_library_id?: ?int, follow_up_due_date?: ?string, follow_up_title?: ?string, follow_up_notes?: ?string, follow_up_quantity?: ?int}  $payload
     *
     * @throws FindingAlreadyDecidedException if this Finding already has a Decision.
     * @throws FindingMissingPartException if decision=REPLACE_PART and the Finding has no part_id.
     * @throws InvalidFollowUpQuantityException if the resolved replacement quantity is < 1 or exceeds the equipment's current active installation count.
     * @throws TaskLibraryEquipmentMismatchException if follow_up_task_library_id doesn't belong to the Finding's equipment.
     */
    public function decide(Finding $finding, User $actor, array $payload): MaintenanceDecision
    {
        return DB::transaction(function () use ($finding, $actor, $payload) {
            // Lock the Finding row so two concurrent decision requests on the
            // same Finding can't both pass this check before either commits —
            // the DB's own unique(finding_id) constraint on
            // maintenance_decisions is the last line of defense, this is the
            // business-friendly one in front of it.
            $locked = Finding::whereKey($finding->id)->lockForUpdate()->firstOrFail();

            if ($locked->decision()->exists()) {
                throw new FindingAlreadyDecidedException($locked->id);
            }

            $decisionType = MaintenanceDecisionType::from($payload['decision']);

            $decision = MaintenanceDecision::create([
                'finding_id' => $locked->id,
                'decision' => $decisionType,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'notes' => $payload['notes'] ?? null,
            ]);

            $task = match ($decisionType) {
                MaintenanceDecisionType::Monitor, MaintenanceDecisionType::ScheduleMaintenance => $this->createFollowUpTask($locked, $payload),
                default => null,
            };

            $replacementRequest = $decisionType === MaintenanceDecisionType::ReplacePart
                ? $this->createReplacementRequest($locked, $actor, $payload)
                : null;

            if ($task || $replacementRequest) {
                $decision->update([
                    'resulting_task_id' => $task?->id,
                    'resulting_replacement_request_id' => $replacementRequest?->id,
                ]);
            }

            return $decision->fresh(['finding', 'decidedBy', 'resultingTask', 'resultingReplacementRequest']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createFollowUpTask(Finding $finding, array $payload): ?Task
    {
        $libraryId = $payload['follow_up_task_library_id'] ?? null;
        $title = $payload['follow_up_title'] ?? null;
        $dueDate = $payload['follow_up_due_date'] ?? null;

        if (! $libraryId && ! $title) {
            // MONITOR with no follow-up requested — nothing to create.
            // SCHEDULE_MAINTENANCE always requires one of these, already
            // enforced by StoreMaintenanceDecisionRequest.
            return null;
        }

        if ($libraryId) {
            $library = TaskLibrary::findOrFail($libraryId);

            if ($library->equipment_id !== $finding->equipment_id) {
                throw new TaskLibraryEquipmentMismatchException($library->id, $finding->equipment_id);
            }

            return $this->scheduling->schedule($library, $dueDate, null);
        }

        return $this->scheduling->scheduleAdHoc(
            equipment: $finding->equipment,
            title: $title,
            dueDate: $dueDate,
            partId: $finding->part_id,
            description: $payload['follow_up_notes'] ?? null,
            assignedTo: null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createReplacementRequest(Finding $finding, User $actor, array $payload): PartReplacementRequest
    {
        if ($finding->part_id === null) {
            throw new FindingMissingPartException($finding->id);
        }

        $activeCount = PartInstallation::query()
            ->where('equipment_id', $finding->equipment_id)
            ->where('part_id', $finding->part_id)
            ->whereNull('removed_at')
            ->count();

        $quantity = $this->resolveReplacementQuantity($finding, $payload);

        if ($quantity < 1 || $quantity > $activeCount) {
            throw new InvalidFollowUpQuantityException($quantity, $activeCount);
        }

        $reason = $finding->description;
        if (! empty($payload['notes'])) {
            $reason .= ' — '.$payload['notes'];
        }

        return PartReplacementRequest::create([
            'part_id' => $finding->part_id,
            'equipment_id' => $finding->equipment_id,
            'quantity_used' => $quantity,
            'event_type' => ReplacementRequestEventType::Maintenance,
            'requested_by_name' => $actor->name,
            'reason' => $reason,
            'status' => ReplacementRequestStatus::Pending,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveReplacementQuantity(Finding $finding, array $payload): int
    {
        if (isset($payload['follow_up_quantity'])) {
            return (int) $payload['follow_up_quantity'];
        }

        // Main case: a Finding is recorded before any replacement happens
        // (TaskPartCheck.quantity_used is null then — see
        // TaskService::completeChecklist()), so quantity_planned is the only
        // authoritative number available. source_type=PartReplacementRequest
        // has no creation flow yet (Step 4 scope), so it has no analogous
        // quantity to inherit — 1 is the safe default if that ever changes.
        if ($finding->source_type === FindingSourceType::TaskPartCheck && $finding->taskPartCheck) {
            return $finding->taskPartCheck->quantity_planned;
        }

        return 1;
    }
}
