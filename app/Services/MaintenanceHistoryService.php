<?php

namespace App\Services;

use App\Enums\FindingSourceType;
use App\Enums\MaintenanceHistoryEventType;
use App\Enums\TaskStatus;
use App\Models\Branch;
use App\Models\Finding;
use App\Models\MaintenanceDecision;
use App\Models\PartRepair;
use App\Models\PartReplacementRequest;
use App\Models\Task;
use App\Models\TaskPartCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Phase 2 Step 5 — a pure READ model over data that already exists. No new
 * table, no write path: every row here is normalized straight off Task,
 * Finding, MaintenanceDecision, PartReplacementRequest, or PartRepair.
 *
 * Event granularity (deliberately decided, not incidental — see Step 5's
 * final report for the full rationale): exactly one row per underlying
 * source record, never one row per "thing that changed on it".
 *   - PM        <- one Task with status=Completed (any origin: Task
 *                  Library, Part Lifetime, or an ad-hoc/MaintenanceDecision
 *                  follow-up Task — Task itself doesn't distinguish these,
 *                  and splitting further isn't asked for).
 *   - FINDING   <- one Finding.
 *   - DECISION  <- one MaintenanceDecision.
 *   - FAILURE / BREAKDOWN / MAINTENANCE
 *               <- one PartReplacementRequest, split by its OWN
 *                  event_type column (never re-derived from the part's
 *                  current replacement_strategy — see
 *                  ReplacementRequestEventType). A request's approval
 *                  outcome (old/new installation pairs, reviewer) is
 *                  surfaced as a `replacement` detail block on that SAME
 *                  row, not as a second "Replacement Approved" row — the
 *                  spec's own worked example splits those into two rows,
 *                  but that fans one physical replacement into multiple
 *                  visually-identical "activity" entries, which section 6
 *                  explicitly warns against. A status change is a mutation
 *                  of the request, not a second historical fact.
 *   - REPAIR    <- one PartRepair, with its own disposition/cost/dates as
 *                  a `repair` detail block, not one row per disposition
 *                  transition.
 *
 * No pagination: the closest existing precedent for a branch-wide
 * chronological feed (ActivityLogController::index(), PartStockController
 * ::branchLedger()) doesn't paginate either — both bound the fetch instead
 * (limit + optional date range). This follows that same convention rather
 * than inventing a new one; each source query is bounded the same way.
 */
class MaintenanceHistoryService
{
    private const PER_SOURCE_LIMIT = 500;

    /**
     * @param  array{equipment_id?: ?int, part_id?: ?int, event_type?: ?string, date_from?: ?string, date_to?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function forBranch(Branch $branch, array $filters = []): Collection
    {
        $eventType = isset($filters['event_type']) ? MaintenanceHistoryEventType::from($filters['event_type']) : null;
        $equipmentId = $filters['equipment_id'] ?? null;
        $partId = $filters['part_id'] ?? null;
        $dateFrom = isset($filters['date_from']) ? Date::parse($filters['date_from'])->startOfDay() : null;
        $dateTo = isset($filters['date_to']) ? Date::parse($filters['date_to'])->endOfDay() : null;

        $rows = collect();

        if (! $eventType || $eventType === MaintenanceHistoryEventType::Pm) {
            $rows = $rows->concat($this->pmRows($branch, $equipmentId, $partId, $dateFrom, $dateTo));
        }

        if (! $eventType || $eventType === MaintenanceHistoryEventType::Finding) {
            $rows = $rows->concat($this->findingRows($branch, $equipmentId, $partId, $dateFrom, $dateTo));
        }

        if (! $eventType || $eventType === MaintenanceHistoryEventType::Decision) {
            $rows = $rows->concat($this->decisionRows($branch, $equipmentId, $partId, $dateFrom, $dateTo));
        }

        if (! $eventType || in_array($eventType, [
            MaintenanceHistoryEventType::Failure,
            MaintenanceHistoryEventType::Breakdown,
            MaintenanceHistoryEventType::Maintenance,
        ], true)) {
            $rows = $rows->concat($this->replacementRows($branch, $equipmentId, $partId, $dateFrom, $dateTo, $eventType));
        }

        if (! $eventType || $eventType === MaintenanceHistoryEventType::Repair) {
            $rows = $rows->concat($this->repairRows($branch, $equipmentId, $partId, $dateFrom, $dateTo));
        }

        return $rows
            ->sortByDesc(fn (array $row) => $row['event_date']->format('Y-m-d H:i:s.u').'|'.$row['source_type'].':'.$row['source_id'])
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function pmRows(Branch $branch, ?int $equipmentId, ?int $partId, ?Carbon $from, ?Carbon $to): Collection
    {
        $tasks = Task::query()
            ->whereHas('equipment.machine.line', fn ($q) => $q->where('branch_id', $branch->id))
            ->where('status', TaskStatus::Completed)
            // A Completed Task always has completed_at set by
            // TaskService::complete() — this guard is only defensive, so a
            // row with no date can never reach the sort below.
            ->whereNotNull('completed_at')
            ->when($equipmentId, fn ($q) => $q->where('equipment_id', $equipmentId))
            ->when($partId, function ($q) use ($partId) {
                $q->where(function ($q2) use ($partId) {
                    $q2->whereHas('partChecks', fn ($q3) => $q3->where('part_id', $partId))
                        ->orWhereHas('partStock', fn ($q3) => $q3->where('part_id', $partId));
                });
            })
            ->when($from, fn ($q) => $q->where('completed_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('completed_at', '<=', $to))
            ->with([
                'equipment', 'assignee', 'partStock.part',
                'partChecks.part', 'partChecks.partInstallation', 'partChecks.oldInstallation.part',
            ])
            ->latest('completed_at')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get();

        return $tasks->map(function (Task $task) {
            $replacedChecks = $task->partChecks->filter(fn (TaskPartCheck $check) => $check->is_replaced);
            $distinctPartIds = $replacedChecks->pluck('part_id')->unique();
            // Only collapse to a single top-level part when the Task's
            // replaced checks are unambiguously one part — a Task Library
            // recipe with several different parts (e.g. Bearing A + Seal B)
            // has no single "the part" to name here, same as before.
            $singlePart = $distinctPartIds->count() === 1 ? $replacedChecks->first()->part : null;

            return [
                'id' => "task:{$task->id}",
                'event_type' => MaintenanceHistoryEventType::Pm->value,
                'event_date' => $task->completed_at,
                'equipment_id' => $task->equipment_id,
                'equipment' => $task->equipment?->name,
                'part_id' => $singlePart?->id ?? $task->partStock?->part_id,
                'part' => $singlePart?->name ?? $task->partStock?->part?->name,
                'title' => $task->title,
                'description' => $task->description,
                'status' => $task->status->value,
                'performed_by' => $task->assignee?->name,
                'finding' => null,
                'decision' => null,
                'action' => null,
                'replacement' => $this->pmReplacementDetail($replacedChecks, $distinctPartIds),
                'repair' => null,
                'source' => null,
                'source_type' => 'task',
                'source_id' => $task->id,
            ];
        });
    }

    /**
     * Planned-replacement detail for a completed Task's checklist —
     * Phase 3 Step 2/3. Same `items[]` shape as replacementRows() below, for
     * consistency, but built from TaskPartCheck (the source of truth for a
     * checklist-driven replacement) instead of PartReplacementRequestItem.
     *
     * old_installation/old_part come from TaskPartCheck.old_installation_id
     * (Phase 3 Step 3) — persisted by TaskService::completeChecklist()
     * alongside the existing part_installation_id (the NEW installation),
     * at the exact moment it resolves which installation a replacement is
     * closing out. Both sides are therefore authoritative, the same way
     * PartReplacementRequestItem already is for the Failure/Breakdown/
     * Maintenance path below. Null on either side is a legitimate state,
     * never inferred: a non-replacement check, a fresh/first-time install
     * with nothing to replace, or (for old_installation_id only) any row
     * completed before this column existed — never guessed via slot_label,
     * timestamp proximity, or "latest removed installation".
     *
     * @param  Collection<int, TaskPartCheck>  $replacedChecks
     * @param  Collection<int, int>  $distinctPartIds
     * @return array{quantity_used: ?int, reviewed_by: null, reviewed_at: null, items: list<array{old_installation: ?string, old_part: ?string, new_installation: ?string, new_part: ?string, quantity_used: ?int}>}|null
     */
    private function pmReplacementDetail(Collection $replacedChecks, Collection $distinctPartIds): ?array
    {
        if ($replacedChecks->isEmpty()) {
            return null;
        }

        return [
            // Only meaningful when every replaced check is the same part —
            // summing quantities across different parts would be a
            // meaningless number, not a real total (see per-item
            // quantity_used below for the multi-part case instead).
            'quantity_used' => $distinctPartIds->count() === 1 ? $replacedChecks->sum('quantity_used') : null,
            // No review/approval step exists for a Task completion — this
            // stays null here, unlike the Failure/Breakdown/Maintenance
            // block, where it's a real, populated field.
            'reviewed_by' => null,
            'reviewed_at' => null,
            'items' => $replacedChecks->map(fn (TaskPartCheck $check) => [
                'old_installation' => $check->oldInstallation?->slot_label,
                'old_part' => $check->oldInstallation?->part?->name,
                'new_installation' => $check->partInstallation?->slot_label,
                'new_part' => $check->part?->name,
                'quantity_used' => $check->quantity_used,
            ])->values()->all(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function findingRows(Branch $branch, ?int $equipmentId, ?int $partId, ?Carbon $from, ?Carbon $to): Collection
    {
        $findings = Finding::query()
            ->whereHas('equipment.machine.line', fn ($q) => $q->where('branch_id', $branch->id))
            ->when($equipmentId, fn ($q) => $q->where('equipment_id', $equipmentId))
            ->when($partId, fn ($q) => $q->where('part_id', $partId))
            ->when($from, fn ($q) => $q->where('reported_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('reported_at', '<=', $to))
            ->with([
                'equipment', 'part', 'reporter', 'taskPartCheck.task', 'partReplacementRequest',
                'decision.resultingTask', 'decision.resultingReplacementRequest',
            ])
            ->latest('reported_at')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get();

        return $findings->map(fn (Finding $finding) => [
            'id' => "finding:{$finding->id}",
            'event_type' => MaintenanceHistoryEventType::Finding->value,
            'event_date' => $finding->reported_at,
            'equipment_id' => $finding->equipment_id,
            'equipment' => $finding->equipment?->name,
            'part_id' => $finding->part_id,
            'part' => $finding->part?->name,
            'title' => 'Finding: '.$finding->result->value,
            'description' => $finding->description,
            'status' => null,
            'performed_by' => $finding->reporter?->name,
            'finding' => $this->findingSummary($finding),
            'decision' => $finding->decision ? $this->decisionSummary($finding->decision) : null,
            'action' => null,
            'replacement' => null,
            'repair' => null,
            'source' => $this->findingSourceSummary($finding),
            'source_type' => 'finding',
            'source_id' => $finding->id,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function decisionRows(Branch $branch, ?int $equipmentId, ?int $partId, ?Carbon $from, ?Carbon $to): Collection
    {
        $decisions = MaintenanceDecision::query()
            ->whereHas('finding.equipment.machine.line', fn ($q) => $q->where('branch_id', $branch->id))
            ->when($equipmentId, fn ($q) => $q->whereHas('finding', fn ($q2) => $q2->where('equipment_id', $equipmentId)))
            ->when($partId, fn ($q) => $q->whereHas('finding', fn ($q2) => $q2->where('part_id', $partId)))
            ->when($from, fn ($q) => $q->where('decided_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('decided_at', '<=', $to))
            ->with(['finding.equipment', 'finding.part', 'decidedBy', 'resultingTask', 'resultingReplacementRequest'])
            ->latest('decided_at')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get();

        return $decisions->map(fn (MaintenanceDecision $decision) => [
            'id' => "maintenance_decision:{$decision->id}",
            'event_type' => MaintenanceHistoryEventType::Decision->value,
            'event_date' => $decision->decided_at,
            'equipment_id' => $decision->finding->equipment_id,
            'equipment' => $decision->finding->equipment?->name,
            'part_id' => $decision->finding->part_id,
            'part' => $decision->finding->part?->name,
            'title' => 'Decision: '.$decision->decision->value,
            'description' => $decision->notes,
            'status' => null,
            'performed_by' => $decision->decidedBy?->name,
            'finding' => $this->findingSummary($decision->finding),
            'decision' => $this->decisionSummary($decision),
            'action' => $decision->decision->value,
            'replacement' => null,
            'repair' => null,
            'source' => null,
            'source_type' => 'maintenance_decision',
            'source_id' => $decision->id,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function replacementRows(
        Branch $branch,
        ?int $equipmentId,
        ?int $partId,
        ?Carbon $from,
        ?Carbon $to,
        ?MaintenanceHistoryEventType $eventType,
    ): Collection {
        $requests = PartReplacementRequest::query()
            ->whereHas('equipment.machine.line', fn ($q) => $q->where('branch_id', $branch->id))
            ->when($equipmentId, fn ($q) => $q->where('equipment_id', $equipmentId))
            ->when($partId, fn ($q) => $q->where('part_id', $partId))
            ->when($eventType, fn ($q) => $q->where('event_type', strtolower($eventType->value)))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->with([
                'part', 'equipment', 'reviewedBy',
                'items.oldInstallation.part', 'items.newInstallation.part',
            ])
            ->latest('created_at')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get();

        // One query for every Decision that resulted in one of these
        // requests, so a MAINTENANCE row can point back to the Decision that
        // created it — the reverse direction of MaintenanceDecision's own
        // resulting_replacement_request_id pointer, not a new relation.
        $decisionByRequestId = MaintenanceDecision::query()
            ->whereIn('resulting_replacement_request_id', $requests->pluck('id'))
            ->with(['decidedBy', 'resultingTask', 'resultingReplacementRequest'])
            ->get()
            ->keyBy('resulting_replacement_request_id');

        return $requests->map(function (PartReplacementRequest $request) use ($decisionByRequestId) {
            $decision = $decisionByRequestId->get($request->id);

            return [
                'id' => "part_replacement_request:{$request->id}",
                'event_type' => strtoupper($request->event_type->value),
                'event_date' => $request->created_at,
                'equipment_id' => $request->equipment_id,
                'equipment' => $request->equipment?->name,
                'part_id' => $request->part_id,
                'part' => $request->part?->name,
                'title' => 'Replacement Request ('.$request->event_type->label().')',
                'description' => $request->reason,
                'status' => $request->status->value,
                'performed_by' => $request->requested_by_name,
                'finding' => null,
                'decision' => $decision ? $this->decisionSummary($decision) : null,
                'action' => null,
                'replacement' => [
                    'quantity_used' => $request->quantity_used,
                    'reviewed_by' => $request->reviewedBy?->name,
                    'reviewed_at' => $request->reviewed_at,
                    'items' => $request->items->map(fn ($item) => [
                        'old_installation' => $item->oldInstallation?->slot_label,
                        'old_part' => $item->oldInstallation?->part?->name,
                        'new_installation' => $item->newInstallation?->slot_label,
                        'new_part' => $item->newInstallation?->part?->name,
                        // Always 1 here — PartReplacementService::approve()
                        // creates exactly one item per physical unit
                        // swapped, regardless of the request's overall
                        // quantity_used. Present for shape consistency with
                        // the PM replacement block's per-item quantity.
                        'quantity_used' => 1,
                    ])->all(),
                ],
                'repair' => null,
                'source' => null,
                'source_type' => 'part_replacement_request',
                'source_id' => $request->id,
            ];
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function repairRows(Branch $branch, ?int $equipmentId, ?int $partId, ?Carbon $from, ?Carbon $to): Collection
    {
        $repairs = PartRepair::query()
            ->whereHas('partInstallation.equipment.machine.line', fn ($q) => $q->where('branch_id', $branch->id))
            ->when($equipmentId, fn ($q) => $q->whereHas('partInstallation', fn ($q2) => $q2->where('equipment_id', $equipmentId)))
            ->when($partId, fn ($q) => $q->whereHas('partUnit', fn ($q2) => $q2->where('part_id', $partId)))
            ->when($from, fn ($q) => $q->where('removed_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('removed_at', '<=', $to))
            ->with(['partUnit.part', 'partInstallation.equipment'])
            ->latest('removed_at')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get();

        return $repairs->map(fn (PartRepair $repair) => [
            'id' => "part_repair:{$repair->id}",
            'event_type' => MaintenanceHistoryEventType::Repair->value,
            'event_date' => $repair->removed_at,
            'equipment_id' => $repair->partInstallation?->equipment_id,
            'equipment' => $repair->partInstallation?->equipment?->name,
            'part_id' => $repair->partUnit?->part_id,
            'part' => $repair->partUnit?->part?->name,
            'title' => 'Part Repair',
            'description' => $repair->notes,
            'status' => $repair->disposition->value,
            // No actor column exists on part_repairs — see PartRepair model.
            'performed_by' => null,
            'finding' => null,
            'decision' => null,
            'action' => null,
            'replacement' => null,
            'repair' => [
                'disposition' => $repair->disposition->value,
                'repair_cost' => $repair->repair_cost,
                'removed_at' => $repair->removed_at,
                'repaired_at' => $repair->repaired_at,
            ],
            'source' => null,
            'source_type' => 'part_repair',
            'source_id' => $repair->id,
        ]);
    }

    /**
     * Step 6 addition (Finding & Action UI): a Finding's own row didn't
     * carry any identifying detail about what it was reported against —
     * "PM Checklist" vs "Replacement Request" is exactly the source
     * context the Finding Detail view needs (see Step 6 spec section 6),
     * and there was nothing here to surface it. Minimal, read-only, same
     * pattern as the existing `replacement`/`repair` detail blocks — no
     * new query shape, no write path.
     *
     * @return array{type: string, task_id?: int, task_title?: ?string, request_id?: int}|null
     */
    private function findingSourceSummary(Finding $finding): ?array
    {
        return match ($finding->source_type) {
            FindingSourceType::TaskPartCheck => $finding->taskPartCheck ? [
                'type' => 'task_part_check',
                'task_id' => $finding->taskPartCheck->task_id,
                'task_title' => $finding->taskPartCheck->task?->title,
            ] : null,
            FindingSourceType::PartReplacementRequest => $finding->partReplacementRequest ? [
                'type' => 'part_replacement_request',
                'request_id' => $finding->source_id,
            ] : null,
        };
    }

    /**
     * @return array{id: int, result: string, severity: ?string, recommendation: ?string}
     */
    private function findingSummary(Finding $finding): array
    {
        return [
            'id' => $finding->id,
            'result' => $finding->result->value,
            'severity' => $finding->severity?->value,
            'recommendation' => $finding->recommendation,
        ];
    }

    /**
     * @return array{id: int, decision: string, decided_by: ?string, decided_at: string, notes: ?string, resulting_task_id: ?int, resulting_task_title: ?string, resulting_replacement_request_id: ?int, resulting_replacement_request_status: ?string}
     */
    private function decisionSummary(MaintenanceDecision $decision): array
    {
        return [
            'id' => $decision->id,
            'decision' => $decision->decision->value,
            'decided_by' => $decision->decidedBy?->name,
            // Step 6 additions: decided_at/notes so a Finding row's nested
            // `decision` summary is self-contained for the Finding Detail
            // view (section 5) without a second DECISION-typed fetch, and
            // resulting_task_title/resulting_replacement_request_status so
            // the UI can show "Follow-up Task #123: <title>" instead of a
            // bare id (section 12) — both already eager-loaded, no N+1.
            'decided_at' => $decision->decided_at,
            'notes' => $decision->notes,
            'resulting_task_id' => $decision->resulting_task_id,
            'resulting_task_title' => $decision->resultingTask?->title,
            'resulting_replacement_request_id' => $decision->resulting_replacement_request_id,
            'resulting_replacement_request_status' => $decision->resultingReplacementRequest?->status?->value,
        ];
    }
}
