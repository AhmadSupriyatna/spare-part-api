<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Budget;
use App\Models\Equipment;
use App\Models\Finding;
use App\Models\Fp3Request;
use App\Models\Location;
use App\Models\Machine;
use App\Models\MaintenanceDecision;
use App\Models\PartInstallation;
use App\Models\PartRepair;
use App\Models\PartReplacementRequest;
use App\Models\PartStock;
use App\Models\PartSupplier;
use App\Models\PartUnit;
use App\Models\PartUnitActionRequest;
use App\Models\ProductionLine;
use App\Models\Rack;
use App\Models\RackLevel;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\TaskLibrary;
use App\Models\TaskLibraryPart;
use App\Models\WorkOrder;

/**
 * Resolves "which branch does this route-bound model belong to", so
 * EnsureBranchAccess can check it against the acting user's assigned
 * branches without every controller having to know how to walk its own
 * model's relations back to a branch_id.
 */
class BranchScopeResolver
{
    public function resolve(mixed $model): ?int
    {
        return match (true) {
            $model instanceof Branch => $model->id,
            $model instanceof ProductionLine, $model instanceof Supplier, $model instanceof Location, $model instanceof PartStock, $model instanceof Rack, $model instanceof Budget, $model instanceof Fp3Request => $model->branch_id,
            $model instanceof RackLevel => $model->rack->branch_id,
            $model instanceof PartUnitActionRequest => $model->branch_id,
            // "Mesin Luar Line": a Machine may now resolve its branch via
            // its own branch_id (outside-line) instead of always line->branch_id.
            $model instanceof Machine => $model->resolvedBranchId(),
            // Phase 3B "Asset Outside Line": Equipment::resolvedBranchId()
            // handles both production (via machine->line) and an
            // outside-line asset (its own branch_id) — this is the
            // authorization chokepoint, so every model that resolves a
            // branch through an equipment_id now goes through it too,
            // instead of repeating the (previously crash-prone for an
            // outside-line asset) raw ->machine->line->branch_id chain.
            $model instanceof Equipment => $model->resolvedBranchId(),
            $model instanceof Finding => $model->equipment->resolvedBranchId(),
            // Step 7: without this, ActivityLogController::index()'s
            // branch-filter (this same resolver) would silently drop every
            // MaintenanceDecision activity row — decisions never resolve to
            // a branch on their own, only via their Finding's equipment.
            $model instanceof MaintenanceDecision => $model->finding->equipment->resolvedBranchId(),
            $model instanceof WorkOrder, $model instanceof Task, $model instanceof TaskLibrary, $model instanceof PartInstallation, $model instanceof PartReplacementRequest => $model->equipment->resolvedBranchId(),
            $model instanceof TaskLibraryPart => $model->taskLibrary->equipment->resolvedBranchId(),
            $model instanceof PartSupplier => $model->supplier->branch_id,
            $model instanceof PartRepair => $model->partInstallation?->equipment?->resolvedBranchId(),
            $model instanceof PartUnit => $this->resolvePartUnitBranch($model),
            default => null,
        };
    }

    private function resolvePartUnitBranch(PartUnit $unit): ?int
    {
        $installation = $unit->currentInstallation() ?? $unit->installations()->latest('installed_at')->first();

        return $installation?->equipment?->resolvedBranchId();
    }
}
