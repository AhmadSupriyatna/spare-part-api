<?php

namespace App\Http\Controllers\Api;

use App\Enums\PartRepairDisposition;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePartRepairRequest;
use App\Http\Resources\PartRepairResource;
use App\Models\PartRepair;
use App\Services\PartLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PartRepairController extends Controller
{
    public function __construct(private readonly PartLifecycleService $lifecycle) {}

    /**
     * Repair records for the Repair Part board — scoped to a branch via the
     * removed installation's equipment (a PartUnit itself isn't tied to one
     * branch, but where it was just pulled from is), same
     * branch_id-query-param pattern as TaskController::mine(). Optionally
     * filtered to one disposition.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $branchId = $request->integer('branch_id') ?: null;

        if ($branchId && ! $user->hasAnyRole(['superadmin', 'supervisor'])) {
            $allowed = $user->branches()->pluck('branches.id');
            if (! $allowed->contains($branchId)) {
                $branchId = null;
            }
        }

        $query = PartRepair::query()->with([
            'partUnit.part',
            'partUnit.installations',
            'partInstallation.equipment.machine.line',
        ]);

        if ($branchId) {
            $query->whereHas(
                'partInstallation.equipment.machine.line',
                fn ($q) => $q->where('branch_id', $branchId)
            );
        }

        if ($disposition = $request->string('disposition')->toString()) {
            $query->where('disposition', $disposition);
        }

        return PartRepairResource::collection($query->latest('removed_at')->get());
    }

    /**
     * Move a repair forward: in_repair, repaired (unit becomes available to
     * reinstall), or scrapped (unit is done for good) — Admin Spare
     * Part/Superadmin both do this from the board's normal card actions.
     * Reverting a status backward (or un-scrapping) is a correction for a
     * mistaken click, not a normal day-to-day action, so it's restricted to
     * Superadmin only.
     */
    public function update(UpdatePartRepairRequest $request, PartRepair $partRepair): PartRepairResource
    {
        $data = $request->validated();
        $target = PartRepairDisposition::from($data['disposition']);

        if ($target->isBackwardFrom($partRepair->disposition)) {
            abort_unless(
                $request->user()->hasRole('superadmin'),
                403,
                'Hanya Superadmin yang bisa mengembalikan status perbaikan.'
            );
        }

        $repair = $this->lifecycle->updateDisposition(
            $partRepair,
            $target,
            $data['notes'] ?? null,
            $data['estimated_completion_date'] ?? null,
            isset($data['repair_cost']) ? (string) $data['repair_cost'] : null,
        );

        return new PartRepairResource(
            $repair->load(['partUnit.part', 'partUnit.installations', 'partInstallation.equipment.machine.line'])
        );
    }

    /**
     * Superadmin-only: undo a mistaken removal entirely (see
     * PartLifecycleService::undoRemoval()) rather than just deleting the
     * repair row, so the equipment's install history stays consistent too.
     */
    public function destroy(PartRepair $partRepair): JsonResponse
    {
        $this->lifecycle->undoRemoval($partRepair);

        return response()->json(['message' => 'Pelepasan part dibatalkan, unit dikembalikan ke status semula.']);
    }
}
