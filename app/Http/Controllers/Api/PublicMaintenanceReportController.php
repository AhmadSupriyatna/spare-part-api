<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShowPublicMaintenanceReportRequest;
use App\Http\Resources\CompanySettingResource;
use App\Http\Resources\TaskResource;
use App\Models\CompanySetting;
use App\Models\Machine;
use App\Models\ProductionLine;
use App\Services\MaintenanceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Unauthenticated "QR Validator" for a Laporan Pemeriksaan Mesin — scanning
 * the QR printed on a report (built as `${origin}/maintenance-report/scan?
 * scope=machine|line&id=&from=&to=`) lands here with no login, same
 * shop-floor-friendly access level as PublicMachineMonitoringController.
 * Re-derives the report live from current data rather than replaying a
 * frozen snapshot from print time — same "read model, not a stored
 * artifact" choice MaintenanceHistoryService and the monitoring QR already
 * make elsewhere in this app. Read-only: nothing here writes anything.
 */
class PublicMaintenanceReportController extends Controller
{
    public function __construct(private readonly MaintenanceReportService $reports) {}

    public function show(ShowPublicMaintenanceReportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();

        if ($data['scope'] === 'machine') {
            $machine = Machine::with(['line.branch', 'branch'])->findOrFail($data['id']);
            $tasks = $this->reports->forMachine($machine, $from, $to);
            $branch = $machine->line?->branch ?? $machine->branch;
            $machineName = $machine->name;
            $lineName = $machine->line?->name;
            $runtimeHours = $machine->line ? $this->reports->runtimeHoursAsOf($machine->line, $to) : null;
        } else {
            $line = ProductionLine::with('branch')->findOrFail($data['id']);
            $tasks = $this->reports->forLine($line, $from, $to);
            $branch = $line->branch;
            $machineName = null;
            $lineName = $line->name;
            $runtimeHours = $this->reports->runtimeHoursAsOf($line, $to);
        }

        return response()->json(['data' => [
            'title' => $data['scope'] === 'line' ? 'Laporan Pemeriksaan Line' : 'Laporan Pemeriksaan Mesin',
            'scope' => $data['scope'],
            'machine_name' => $machineName,
            'line_name' => $lineName,
            'branch_name' => $branch?->name,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'runtime_hours' => $runtimeHours,
            'supervisor_name' => $branch ? $this->reports->supervisorNameForBranch($branch->id) : null,
            'tasks' => TaskResource::collection($tasks),
            'company' => new CompanySettingResource(CompanySetting::current()),
        ]]);
    }
}
