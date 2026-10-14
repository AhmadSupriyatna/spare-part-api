<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DestroyWithPasswordRequest;
use App\Http\Requests\StoreMachineRequest;
use App\Http\Requests\StoreOutsideLineMachineRequest;
use App\Http\Requests\UpdateMachineRequest;
use App\Http\Resources\MachineResource;
use App\Http\Resources\PartInstallationResource;
use App\Http\Resources\TaskResource;
use App\Models\Branch;
use App\Models\Machine;
use App\Models\PartReplacementRequest;
use App\Models\ProductionLine;
use App\Models\Task;
use App\Services\MachineSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class MachineController extends Controller
{
    public function __construct(private MachineSummaryService $summary) {}

    public function index(ProductionLine $line): AnonymousResourceCollection
    {
        return MachineResource::collection($line->machines()->orderBy('name')->get());
    }

    public function store(StoreMachineRequest $request, ProductionLine $line): MachineResource
    {
        $data = $request->validated();
        $data['code'] = Machine::generateCode($data['name'], $line->id);

        $machine = $line->machines()->create($data);

        return new MachineResource($machine);
    }

    /**
     * "Mesin Luar Line" — every outside-line machine for this branch (a
     * compressor room, a genset...), independent of any production Line.
     * The existing line-scoped index() above is completely unchanged and
     * still the only way to list production Machines.
     */
    public function indexOutsideLine(Branch $branch): AnonymousResourceCollection
    {
        return MachineResource::collection($branch->machines()->orderBy('name')->get());
    }

    /**
     * Creates an outside-line machine directly under a Branch — no fake
     * Line. Code is system-generated the same way production Machine's is,
     * just scoped by Branch instead of Line (see Machine::generateCodeForBranch()).
     */
    public function storeOutsideLine(StoreOutsideLineMachineRequest $request, Branch $branch): MachineResource
    {
        $data = $request->validated();
        $data['code'] = Machine::generateCodeForBranch($data['name'], $branch->id);

        $machine = $branch->machines()->create($data);

        return new MachineResource($machine);
    }

    public function show(Machine $machine): MachineResource
    {
        return new MachineResource($machine->load(['line', 'branch']));
    }

    /**
     * Machine Detail's dashboard data — the authenticated twin of
     * PublicMachineMonitoringController::show(), with cost fields included
     * (installed_part_value, per-month/per-part cost) since this route sits
     * behind auth, unlike the QR-scan monitoring page.
     */
    public function summary(Machine $machine): JsonResponse
    {
        $machine->load(['line.branch', 'branch']);

        $data = $this->summary->build($machine, includeCost: true);

        return response()->json(['data' => [
            'machine' => new MachineResource($machine),
            'equipment_count' => $data['equipment_count'],
            'upcoming_tasks' => TaskResource::collection($data['upcoming_tasks']),
            'upcoming_task_count' => $data['upcoming_task_count'],
            'overdue_task_count' => $data['overdue_task_count'],
            'installations' => PartInstallationResource::collection($data['installations']),
            'monthly_failure_trend' => $data['monthly_failure_trend'],
            'average_lifetime_hours' => $data['average_lifetime_hours'],
            'monthly_consumption_trend' => $data['monthly_consumption_trend'],
            'top_parts_consumed' => $data['top_parts_consumed'],
            'historical_part_lifetime' => $data['historical_part_lifetime'],
            'line_runtime_hours' => $data['line_runtime_hours'],
            'passport_install_history' => $data['passport_install_history'],
            'installed_part_value' => $data['installed_part_value'],
            'cost_by_equipment_this_year' => $data['cost_by_equipment_this_year'],
            'top_parts_consumed_this_year' => $data['top_parts_consumed_this_year'],
            'lifetime_hours_sample_count' => $data['lifetime_hours_sample_count'],
            'lifetime_hours_total' => $data['lifetime_hours_total'],
        ]]);
    }

    /**
     * Machine Detail's mini calendar — one calendar month's PM schedule
     * (any Task status, so a completed/cancelled WO still shows where it
     * fell) plus failure/breakdown events, keyed by date. Deliberately its
     * own month-scoped endpoint rather than folded into summary() (which
     * has no month concept) — authenticated only, like the rest of Machine
     * Detail's deeper drill-downs.
     */
    public function calendar(Request $request, Machine $machine): JsonResponse
    {
        $month = Carbon::parse($request->query('month', now()->format('Y-m')).'-01');
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $equipmentIds = $machine->equipment()->pluck('id');

        $tasks = Task::query()
            ->whereIn('equipment_id', $equipmentIds)
            ->whereBetween('due_date', [$start, $end])
            ->get(['id', 'due_date', 'title', 'status']);

        $failures = PartReplacementRequest::query()
            ->whereIn('equipment_id', $equipmentIds)
            ->whereIn('event_type', ['failure', 'breakdown'])
            ->whereBetween('created_at', [$start, $end])
            ->get(['created_at']);

        $days = [];
        foreach ($tasks as $task) {
            $key = $task->due_date->format('Y-m-d');
            $days[$key]['pm'][] = ['id' => $task->id, 'title' => $task->title, 'status' => $task->status];
        }
        foreach ($failures as $failure) {
            $key = $failure->created_at->format('Y-m-d');
            $days[$key]['failure_count'] = ($days[$key]['failure_count'] ?? 0) + 1;
        }

        return response()->json(['data' => [
            'month' => $start->format('Y-m'),
            'days' => $days,
        ]]);
    }

    public function update(UpdateMachineRequest $request, Machine $machine): MachineResource
    {
        $machine->update($request->validated());

        return new MachineResource($machine);
    }

    public function destroy(DestroyWithPasswordRequest $request, Machine $machine): Response
    {
        $machine->delete();

        return response()->noContent();
    }
}
