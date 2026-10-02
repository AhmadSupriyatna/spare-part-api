<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddLineKwhRequest;
use App\Http\Requests\AddLineRuntimeRequest;
use App\Http\Requests\DestroyWithPasswordRequest;
use App\Http\Requests\StoreLineRequest;
use App\Http\Requests\UpdateLineRequest;
use App\Http\Resources\LineKwhLogResource;
use App\Http\Resources\LineResource;
use App\Http\Resources\LineRuntimeLogResource;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\LineKwhLog;
use App\Models\LineRuntimeLog;
use App\Models\ProductionLine;
use App\Services\MaintenanceReportService;
use App\Services\PartLifetimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LineController extends Controller
{
    public function __construct(
        private readonly PartLifetimeService $lifetime,
        private readonly MaintenanceReportService $reports,
    ) {}

    public function index(Branch $branch): AnonymousResourceCollection
    {
        return LineResource::collection($branch->lines()->orderBy('name')->get());
    }

    public function store(StoreLineRequest $request, Branch $branch): LineResource
    {
        $line = $branch->lines()->create($request->validated());

        return new LineResource($line);
    }

    public function show(ProductionLine $line): LineResource
    {
        return new LineResource($line);
    }

    public function update(UpdateLineRequest $request, ProductionLine $line): LineResource
    {
        $line->update($request->validated());

        return new LineResource($line);
    }

    public function destroy(DestroyWithPasswordRequest $request, ProductionLine $line): Response
    {
        $line->delete();

        return response()->noContent();
    }

    /**
     * Logs a new hour-meter reading: the technician reports the absolute
     * value they see on the meter, and this computes + stores the delta
     * against the last recorded reading rather than trusting a manually
     * calculated delta from the field.
     */
    public function addRuntime(AddLineRuntimeRequest $request, ProductionLine $line): LineResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($request, $line, $data) {
            $locked = ProductionLine::whereKey($line->id)->lockForUpdate()->first();

            LineRuntimeLog::create([
                'line_id' => $locked->id,
                'previous_hours' => $locked->runtime_hours,
                'new_hours' => $data['current_reading'],
                'hours_added' => $data['current_reading'] - $locked->runtime_hours,
                'recorded_by' => $request->user()->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $locked->update(['runtime_hours' => $data['current_reading']]);
        });

        $this->lifetime->autoScheduleAtRisk($line);

        return new LineResource($line->fresh());
    }

    public function runtimeLogs(ProductionLine $line): AnonymousResourceCollection
    {
        return LineRuntimeLogResource::collection(
            $line->runtimeLogs()->with('recordedBy')->paginate(10)
        );
    }

    /**
     * Logs this Line's kWh usage for the calendar week containing
     * `for_date` — upserted by (line, week) so resubmitting the same week
     * corrects it instead of creating a duplicate. Meaning of `value`
     * depends on CompanySetting::kwh_input_mode at submit time, captured
     * onto the row itself (`mode`) so a later mode switch never
     * reinterprets older entries: "reading" treats it as the meter's
     * absolute value (previous/new/kwh_used all derived, like
     * addRuntime()); "direct" treats it as that week's usage as-is. `kva_value`
     * is optional and follows the identical reading/direct duality for kVA;
     * `pf` (Power Factor) is never submitted — derived here as kwh_used/kva_used.
     */
    public function addKwh(AddLineKwhRequest $request, ProductionLine $line): LineKwhLogResource
    {
        $data = $request->validated();
        $mode = CompanySetting::current()->kwh_input_mode;
        $weekStart = Carbon::parse($data['for_date'])->startOfWeek(Carbon::MONDAY)->toDateString();

        // Preserve the existing entry's notes on a correction when the
        // resubmit doesn't include new ones, rather than silently wiping
        // them — the technician correcting just the number shouldn't lose
        // what was written down earlier for that week.
        $existingNotes = LineKwhLog::where('line_id', $line->id)
            ->where('week_start_date', $weekStart)
            ->value('notes');

        if ($mode === 'reading') {
            $previousReading = LineKwhLog::where('line_id', $line->id)
                ->where('week_start_date', '<', $weekStart)
                ->orderByDesc('week_start_date')
                ->value('new_reading') ?? 0;
            $newReading = $data['value'];
            $kwhUsed = $newReading - $previousReading;

            if (array_key_exists('kva_value', $data) && $data['kva_value'] !== null) {
                $previousKvaReading = LineKwhLog::where('line_id', $line->id)
                    ->where('week_start_date', '<', $weekStart)
                    ->orderByDesc('week_start_date')
                    ->value('new_kva_reading') ?? 0;
                $newKvaReading = $data['kva_value'];
                $kvaUsed = $newKvaReading - $previousKvaReading;
            } else {
                $previousKvaReading = null;
                $newKvaReading = null;
                $kvaUsed = null;
            }
        } else {
            $previousReading = null;
            $newReading = null;
            $kwhUsed = $data['value'];

            $previousKvaReading = null;
            $newKvaReading = null;
            $kvaUsed = $data['kva_value'] ?? null;
        }

        // Power Factor = real power / apparent power — derived from this
        // week's kWh and kVA usage, never a typed-in value, so it's null
        // whenever kVA wasn't entered (or came out to 0).
        $pf = ($kvaUsed !== null && $kvaUsed > 0) ? round($kwhUsed / $kvaUsed, 3) : null;

        $log = LineKwhLog::updateOrCreate(
            ['line_id' => $line->id, 'week_start_date' => $weekStart],
            [
                'mode' => $mode,
                'previous_reading' => $previousReading,
                'new_reading' => $newReading,
                'kwh_used' => $kwhUsed,
                'previous_kva_reading' => $previousKvaReading,
                'new_kva_reading' => $newKvaReading,
                'kva_used' => $kvaUsed,
                'pf' => $pf,
                'recorded_by' => $request->user()->id,
                'notes' => $data['notes'] ?? $existingNotes,
            ],
        );

        return new LineKwhLogResource($log->load('recordedBy'));
    }

    public function kwhLogs(ProductionLine $line): AnonymousResourceCollection
    {
        return LineKwhLogResource::collection(
            $line->kwhLogs()->with('recordedBy')->paginate(10)
        );
    }

    /**
     * Laporan Pemeriksaan Mesin's "Running Hours" field — the line's
     * running hours as of a past report period's end date, not today's
     * live total. See MaintenanceReportService::runtimeHoursAsOf().
     */
    public function runtimeAsOf(Request $request, ProductionLine $line): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date']]);

        return response()->json([
            'data' => ['hours' => $this->reports->runtimeHoursAsOf($line, Carbon::parse($data['date'])->endOfDay())],
        ]);
    }

    /**
     * "Laporan kWh" — every Line in this branch, one row each, with that
     * calendar month's total usage. A week is counted in whichever month
     * its Monday (week_start_date) falls in, matching how it was entered.
     */
    public function kwhReport(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $monthStart = Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $rows = $branch->lines()->orderBy('name')->get()->map(function (ProductionLine $line) use ($monthStart, $monthEnd) {
            $logs = LineKwhLog::where('line_id', $line->id)
                ->whereBetween('week_start_date', [$monthStart->toDateString(), $monthEnd->toDateString()]);

            return [
                'line_id' => $line->id,
                'line_code' => $line->code,
                'line_name' => $line->name,
                'total_kwh' => (int) $logs->sum('kwh_used'),
                'entry_count' => $logs->count(),
            ];
        });

        return response()->json(['data' => $rows->values()]);
    }

    /**
     * "Laporan Running Hours Line" — every Line in this branch, one row
     * each, with that calendar month's hour-meter movement. hours_added
     * sums every reading logged within the month (always exact); the
     * start/end readings are contextual only (see
     * MaintenanceReportService::runtimeHoursAsOf()) and null when no
     * reading exists yet at that point in time.
     */
    public function runtimeReport(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $monthStart = Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $rows = $branch->lines()->orderBy('name')->get()->map(function (ProductionLine $line) use ($monthStart, $monthEnd) {
            $hoursAdded = (int) LineRuntimeLog::where('line_id', $line->id)
                ->whereBetween('created_at', [$monthStart->copy()->startOfDay(), $monthEnd->copy()->endOfDay()])
                ->sum('hours_added');

            return [
                'line_id' => $line->id,
                'line_code' => $line->code,
                'line_name' => $line->name,
                'hours_at_start' => $this->reports->runtimeHoursAsOf($line, $monthStart->copy()->subDay()->endOfDay()),
                'hours_at_end' => $this->reports->runtimeHoursAsOf($line, $monthEnd->copy()->endOfDay()),
                'hours_added' => $hoursAdded,
            ];
        });

        return response()->json(['data' => $rows->values()]);
    }
}
