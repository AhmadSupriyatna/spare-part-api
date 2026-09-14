<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePartInstallationRequest;
use App\Http\Resources\PartInstallationResource;
use App\Models\Equipment;
use App\Models\PartInstallation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PartInstallationController extends Controller
{
    public function index(Equipment $equipment): AnonymousResourceCollection
    {
        return PartInstallationResource::collection(
            $equipment->partInstallations()
                ->with(['part', 'installedBy'])
                ->orderByDesc('installed_at')
                ->get()
        );
    }

    /**
     * Install a part onto an equipment. If that part is already actively
     * installed there, the old installation is auto-closed (treated as
     * replaced) rather than allowing two active rows for the same pair.
     */
    public function store(StorePartInstallationRequest $request, Equipment $equipment): PartInstallationResource
    {
        $data = $request->validated();
        $currentRuntimeHours = $equipment->machine->line->runtime_hours;

        $installation = DB::transaction(function () use ($equipment, $request, $data, $currentRuntimeHours) {
            $equipment->partInstallations()
                ->where('part_id', $data['part_id'])
                ->whereNull('removed_at')
                ->update([
                    'removed_at' => now(),
                    'removed_at_runtime_hours' => $currentRuntimeHours,
                ]);

            return $equipment->partInstallations()->create([
                'part_id' => $data['part_id'],
                'installed_at' => $data['installed_at'] ?? now(),
                'installed_at_runtime_hours' => $currentRuntimeHours,
                'installed_by' => $request->user()->id,
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return new PartInstallationResource($installation->load(['part', 'installedBy']));
    }

    public function remove(Request $request, PartInstallation $partInstallation): PartInstallationResource
    {
        $partInstallation->update([
            'removed_at' => now(),
            'removed_at_runtime_hours' => $partInstallation->equipment->machine->line->runtime_hours,
        ]);

        return new PartInstallationResource($partInstallation->load(['part', 'installedBy']));
    }
}
