<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReplacementRequestEventType;
use App\Enums\ReplacementRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReplacementRequestRequest;
use App\Http\Resources\BranchResource;
use App\Http\Resources\EquipmentResource;
use App\Http\Resources\LineResource;
use App\Http\Resources\MachineResource;
use App\Http\Resources\PartResource;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartReplacementRequest;
use App\Models\ProductionLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Unauthenticated endpoints backing the QR-scan breakdown flow: a
 * technician on the shop floor scans a part's QR code, picks where it's
 * going (branch > line > machine > equipment), and submits a replacement
 * request — no login, but nothing here writes to stock or installations.
 * That only happens once an Engineer/Teknisi approves it via the
 * authenticated approval board (see PartReplacementRequestController).
 */
class PublicBreakdownController extends Controller
{
    public function showPart(Part $part): PartResource
    {
        return new PartResource($part);
    }

    public function branches(): AnonymousResourceCollection
    {
        return BranchResource::collection(Branch::where('is_active', true)->orderBy('name')->get());
    }

    public function lines(Branch $branch): AnonymousResourceCollection
    {
        return LineResource::collection(
            $branch->lines()->where('is_active', true)->orderBy('name')->get()
        );
    }

    public function machines(ProductionLine $line): AnonymousResourceCollection
    {
        return MachineResource::collection(
            $line->machines()->where('is_active', true)->orderBy('name')->get()
        );
    }

    public function equipment(Machine $machine): AnonymousResourceCollection
    {
        return EquipmentResource::collection(
            $machine->equipment()->where('is_active', true)->orderBy('name')->get()
        );
    }

    /**
     * The whole point of printing a QR per branch: scanning it should only
     * ever offer the equipment that (a) actually has this part installed
     * right now — read from real PartInstallation records, the same data
     * the Line > Equipment > Part page shows, not a separately-maintained
     * BOM spec — and (b) lives in the branch the QR was printed for, never
     * the full factory hierarchy. Each row also carries how many units of
     * this part are actually active on it right now
     * (active_installation_count), which the scan form uses as the hard
     * ceiling for quantity_used — the same count
     * StoreReplacementRequestRequest re-validates server-side.
     */
    public function equipmentForPartInBranch(Part $part, Branch $branch): AnonymousResourceCollection
    {
        $equipment = Equipment::query()
            ->where('is_active', true)
            ->whereHas('partInstallations', fn ($q) => $q->where('part_id', $part->id)->whereNull('removed_at'))
            ->whereHas('machine.line', fn ($q) => $q->where('branch_id', $branch->id))
            ->withCount(['partInstallations as active_installation_count' => fn ($q) => $q
                ->where('part_id', $part->id)
                ->whereNull('removed_at')])
            ->with('machine.line')
            ->get();

        return EquipmentResource::collection($equipment);
    }

    /**
     * $branch is the plant encoded in the printed QR's own URL
     * (/breakdown/scan/:partId/:branchId) — route-bound the same way
     * equipmentForPartInBranch() above is, so it's never just information
     * the frontend happens to send. StoreReplacementRequestRequest
     * cross-checks that the submitted equipment_id actually lives in this
     * exact branch (see its withValidator()), so a direct API call can't
     * submit an equipment from a different plant than the QR that was
     * scanned.
     *
     * event_type is decided here, server-side, from the part's own
     * replacement_strategy as it stands right now in the database — never
     * from anything the client sends (StoreReplacementRequestRequest
     * doesn't even validate an event_type field, so $request->validated()
     * can't carry one through). QR Scan is one flow for the operator
     * either way; this only tags which kind of event it turned out to be,
     * for Phase 2 (Failure Management vs Breakdown Management) to branch on.
     */
    public function store(StoreReplacementRequestRequest $request, Branch $branch): JsonResponse
    {
        $data = $request->validated();
        $part = Part::findOrFail($data['part_id']);

        $replacementRequest = PartReplacementRequest::create([
            ...$data,
            'status' => ReplacementRequestStatus::Pending,
            'event_type' => ReplacementRequestEventType::fromReplacementStrategy($part->replacement_strategy),
        ]);

        return response()->json([
            'message' => 'Permintaan penggantian terkirim. Menunggu persetujuan Engineer/Teknisi.',
            'data' => ['id' => $replacementRequest->id],
        ], 201);
    }
}
