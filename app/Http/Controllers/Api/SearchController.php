<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\Location;
use App\Models\Part;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    private const LIMIT = 6;

    /**
     * Universal header search. Parts are a global catalog and are always
     * searched; Equipment/Supplier/Location are branch-scoped so they are
     * only searched when a branch_id is given, and that branch_id is
     * clamped to one of the user's own branches unless they are exempt
     * (Superadmin/Supervisor), mirroring EnsureBranchAccess elsewhere.
     */
    public function index(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json([
                'data' => ['parts' => [], 'equipment' => [], 'suppliers' => [], 'locations' => []],
            ]);
        }

        $user = $request->user();
        $branchId = $request->integer('branch_id') ?: null;

        if ($branchId && ! $user->hasAnyRole(['superadmin', 'supervisor'])) {
            $allowed = $user->branches()->pluck('branches.id');
            if (! $allowed->contains($branchId)) {
                $branchId = null;
            }
        }

        $parts = Part::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'ilike', "%{$query}%")
                    ->orWhere('item_master_no', 'ilike', "%{$query}%");
            })
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'item_master_no'])
            ->map(fn (Part $part) => [
                'id' => $part->id,
                'name' => $part->name,
                'item_master_no' => $part->item_master_no,
            ]);

        $suppliers = collect();
        $locations = collect();
        $equipment = collect();

        if ($branchId) {
            $suppliers = Supplier::query()
                ->where('branch_id', $branchId)
                ->where('name', 'ilike', "%{$query}%")
                ->orderBy('name')
                ->limit(self::LIMIT)
                ->get(['id', 'name'])
                ->map(fn (Supplier $supplier) => ['id' => $supplier->id, 'name' => $supplier->name]);

            $locations = Location::query()
                ->where('branch_id', $branchId)
                ->where(function ($q) use ($query) {
                    $q->where('code', 'ilike', "%{$query}%")
                        ->orWhere('description', 'ilike', "%{$query}%");
                })
                ->orderBy('code')
                ->limit(self::LIMIT)
                ->get(['id', 'code', 'description'])
                ->map(fn (Location $location) => [
                    'id' => $location->id,
                    'code' => $location->code,
                    'description' => $location->description,
                ]);

            $equipment = Equipment::query()
                ->whereHas('machine.line', fn ($q) => $q->where('branch_id', $branchId))
                ->where(function ($q) use ($query) {
                    $q->where('name', 'ilike', "%{$query}%")
                        ->orWhere('code', 'ilike', "%{$query}%");
                })
                ->with('machine.line')
                ->orderBy('name')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (Equipment $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'code' => $item->code,
                    'machine_id' => $item->machine_id,
                    'machine_name' => $item->machine->name,
                    'line_id' => $item->machine->line_id,
                    'line_name' => $item->machine->line->name,
                ]);
        }

        return response()->json([
            'data' => [
                'parts' => $parts->values(),
                'equipment' => $equipment->values(),
                'suppliers' => $suppliers->values(),
                'locations' => $locations->values(),
            ],
        ]);
    }
}
