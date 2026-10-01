<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\LineRuntimeLog;
use App\Models\Machine;
use App\Models\ProductionLine;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Laporan Pemeriksaan Mesin — every Task (regular PM or Part Lifetime
 * auto-scheduled) due within an explicit date range, for one Machine or
 * every Machine under one Line. Shared by the authenticated report
 * (TaskController) and its public QR-validator twin
 * (PublicMaintenanceReportController) so both stay byte-for-byte the same
 * query — this reads live data, it is not a frozen snapshot of whatever
 * was true at print time.
 */
class MaintenanceReportService
{
    public function forMachine(Machine $machine, Carbon $from, Carbon $to): Collection
    {
        return Task::query()
            ->whereHas('equipment', fn ($q) => $q->where('machine_id', $machine->id))
            ->whereBetween('due_date', [$from, $to])
            ->where('status', '!=', TaskStatus::Cancelled)
            ->with(['equipment', 'assignee', 'partChecks.part', 'partChecks.partInstallation', 'checklistItems'])
            ->orderBy('due_date')
            ->orderBy('equipment_id')
            ->get();
    }

    public function forLine(ProductionLine $line, Carbon $from, Carbon $to): Collection
    {
        return Task::query()
            ->whereHas('equipment.machine', fn ($q) => $q->where('line_id', $line->id))
            ->whereBetween('due_date', [$from, $to])
            ->where('status', '!=', TaskStatus::Cancelled)
            ->with(['equipment.machine', 'assignee', 'partChecks.part', 'partChecks.partInstallation', 'checklistItems'])
            ->orderBy('due_date')
            ->orderBy('equipment_id')
            ->get();
    }

    /**
     * The Line's running hours as of a given date — never today's live
     * total, since a report for a past period must reflect what the line's
     * runtime actually was at the end of that period. Reads the latest
     * LineRuntimeLog entry at or before $asOf; null (never a guess/
     * fallback to the current total) if no log exists that far back.
     */
    public function runtimeHoursAsOf(ProductionLine $line, Carbon $asOf): ?int
    {
        return LineRuntimeLog::where('line_id', $line->id)
            ->where('created_at', '<=', $asOf)
            ->orderByDesc('created_at')
            ->value('new_hours');
    }

    /**
     * The registered "SPV Teknik" for a branch — first user holding the
     * supervisor role assigned to it. Null (never a guess/fallback name) if
     * none is assigned, so the printed signature block just shows the bare
     * "SPV Teknik" label with no name under it.
     */
    public function supervisorNameForBranch(int $branchId): ?string
    {
        return User::role('supervisor')
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $branchId))
            ->orderBy('name')
            ->value('name');
    }
}
