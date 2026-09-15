<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Location;
use App\Models\Machine;
use App\Models\PartInstallation;
use App\Models\PartRepair;
use App\Models\PartReplacementRequest;
use App\Models\PartStock;
use App\Models\PartSupplier;
use App\Models\PartUnit;
use App\Models\PartUnitActionRequest;
use App\Models\ProductionLine;
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
            $model instanceof ProductionLine, $model instanceof Supplier, $model instanceof Location, $model instanceof PartStock => $model->branch_id,
            $model instanceof PartUnitActionRequest => $model->branch_id,
            $model instanceof Machine => $model->line->branch_id,
            $model instanceof Equipment => $model->machine->line->branch_id,
            $model instanceof WorkOrder, $model instanceof Task, $model instanceof TaskLibrary, $model instanceof PartInstallation, $model instanceof PartReplacementRequest => $model->equipment->machine->line->branch_id,
            $model instanceof TaskLibraryPart => $model->taskLibrary->equipment->machine->line->branch_id,
            $model instanceof PartSupplier => $model->supplier->branch_id,
            $model instanceof PartRepair => $model->partInstallation?->equipment?->machine?->line?->branch_id,
            $model instanceof PartUnit => $this->resolvePartUnitBranch($model),
            default => null,
        };
    }

    private function resolvePartUnitBranch(PartUnit $unit): ?int
    {
        $installation = $unit->currentInstallation() ?? $unit->installations()->latest('installed_at')->first();

        return $installation?->equipment?->machine?->line?->branch_id;
    }
}
