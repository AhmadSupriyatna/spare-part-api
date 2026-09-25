<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\PartInstallation;
use App\Models\ProductionLine;
use App\Models\Task;
use App\Models\TaskReschedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Surfaces installed parts whose remaining usable life has dropped below a
 * threshold — the second way a Task can be scheduled onto the PM calendar,
 * alongside picking from a Task Library recipe (see PmSchedulingService).
 * Since 2026-09-16 this also drives *automatic* scheduling: crossing the
 * threshold no longer waits for someone to manually pick a date.
 */
class PartLifetimeService
{
    private const AT_RISK_THRESHOLD_PERCENT = 80;

    public function __construct(private readonly PmSchedulingService $scheduling) {}

    /**
     * @return Collection<int, PartInstallation>
     */
    public function atRiskInstallations(int $branchId): Collection
    {
        return PartInstallation::query()
            ->whereNull('removed_at')
            ->whereHas('equipment.machine.line', fn ($q) => $q->where('branch_id', $branchId))
            ->with([
                'part',
                'equipment.machine.line',
                'installedBy',
                'partUnit.part',
                'partUnit.installations.equipment.machine.line',
            ])
            ->get()
            ->filter(fn (PartInstallation $installation) => ($installation->percentUsed() ?? 0) >= self::AT_RISK_THRESHOLD_PERCENT)
            ->sortByDesc(fn (PartInstallation $installation) => $installation->percentUsed())
            ->values();
    }

    /**
     * Checks every active installation on this line's equipment and, for
     * any at-risk one, either creates its PM task or — if one's already
     * open — recomputes and updates its due date. Called after a
     * runtime-hours update, since that's the only thing that can push
     * percentUsed() up for parts on this specific line, and the user
     * explicitly asked for the schedule to stay live against that input
     * rather than freezing at whatever the first estimate was.
     */
    public function autoScheduleAtRisk(ProductionLine $line): void
    {
        $installations = PartInstallation::query()
            ->whereNull('removed_at')
            ->whereHas('equipment.machine', fn ($q) => $q->where('line_id', $line->id))
            ->with(['part', 'partUnit.part', 'partUnit.installations'])
            ->get()
            ->filter(fn (PartInstallation $installation) => ($installation->percentUsed() ?? 0) >= self::AT_RISK_THRESHOLD_PERCENT);

        $this->createOrRescheduleLifetimeTasks($installations);
    }

    /**
     * Same as autoScheduleAtRisk(), but for every at-risk installation in a
     * whole branch — catches anything that crossed the threshold before
     * this feature existed, or whose auto-created task was since deleted/
     * completed without a fresh runtime-hours update to re-trigger the
     * line-scoped check. Called on every Part Lifetime / PM Schedule page
     * load so an at-risk-but-unscheduled part never just sits there
     * unexplained — viewing either page is what "catches up" the schedule,
     * same idea as PmSchedulingService::ensureRecurringSchedules().
     */
    public function autoScheduleAllAtRisk(int $branchId): void
    {
        $this->createMissingLifetimeTasks($this->atRiskInstallations($branchId));
    }

    /**
     * Create-only: used by the page-view catch-up path
     * (autoScheduleAllAtRisk), which must stay idempotent no-op for an
     * installation that already has a task — otherwise just opening the PM
     * Schedule/Part Lifetime page on a later day would keep nudging the due
     * date forward (computeAutoScheduleDueDate() is anchored to "now") with
     * no real new runtime-hours data behind it. Only the runtime-hours-
     * triggered path (createOrRescheduleLifetimeTasks(), below) is allowed
     * to move an existing task's date.
     *
     * @param  Collection<int, PartInstallation>  $installations
     */
    private function createMissingLifetimeTasks(Collection $installations): void
    {
        foreach ($installations as $installation) {
            if ($installation->hasOpenLifetimeTask()) {
                continue;
            }

            $this->scheduling->scheduleFromLifetime(
                $installation,
                $this->computeAutoScheduleDueDate($installation)->toDateString(),
                null,
            );
        }
    }

    /**
     * @param  Collection<int, PartInstallation>  $installations
     */
    private function createOrRescheduleLifetimeTasks(Collection $installations): void
    {
        foreach ($installations as $installation) {
            $newDueDate = $this->computeAutoScheduleDueDate($installation)->toDateString();
            $existingTask = $installation->openLifetimeTask();

            if ($existingTask) {
                $this->rescheduleIfChanged($existingTask, $newDueDate);

                continue;
            }

            $this->scheduling->scheduleFromLifetime($installation, $newDueDate, null);
        }
    }

    /**
     * Keeps an already-scheduled Part Lifetime task's due date live as new
     * runtime-hours data comes in, instead of freezing it at whatever the
     * first estimate happened to be — every update is logged as a
     * TaskReschedule row (rescheduled_by null marks it as system-driven,
     * not a human drag) so the "kenapa tanggalnya berubah" trail stays
     * visible in the same "Riwayat Reschedule" list as manual reschedules.
     */
    private function rescheduleIfChanged(Task $task, string $newDueDate): void
    {
        $previousDueDate = $task->due_date?->toDateString();

        if ($previousDueDate === $newDueDate) {
            return;
        }

        DB::transaction(function () use ($task, $previousDueDate, $newDueDate) {
            TaskReschedule::create([
                'task_id' => $task->id,
                'previous_due_date' => $previousDueDate,
                'new_due_date' => $newDueDate,
                'reason' => 'Disesuaikan otomatis dari data jam operasi terbaru.',
                'rescheduled_by' => null,
            ]);

            $task->update(['due_date' => $newDueDate]);
        });
    }

    /**
     * remaining hours ÷ avg weekly operating hours = weeks until the part is
     * projected to be fully worn; the due date is the next Monday on or
     * after that point (rounds forward, never lands mid-week), and never
     * earlier than the coming Monday even if the calculation lands in the
     * past (e.g. remaining hours already at 0). The rate is this
     * installation's own Line's (falling back to the company-wide default
     * if that Line hasn't set one) — not one flat company-wide number for
     * every line, since lines don't necessarily all run the same hours/week.
     */
    public function computeAutoScheduleDueDate(PartInstallation $installation): Carbon
    {
        $remainingHours = $installation->partUnit?->remainingHours() ?? 0;
        $avgWeeklyHours = $installation->equipment->machine?->line?->effectiveAvgWeeklyOperatingHours()
            ?? (CompanySetting::current()->avg_weekly_operating_hours ?: 85);
        $weeksNeeded = (int) ceil($remainingHours / $avgWeeklyHours);

        $target = $this->mondayOnOrAfter(now()->addWeeks($weeksNeeded));
        $earliest = $this->mondayOnOrAfter(now());

        return $target->lt($earliest) ? $earliest : $target;
    }

    private function mondayOnOrAfter(Carbon $date): Carbon
    {
        $date = $date->copy()->startOfDay();

        return $date->isMonday() ? $date : $date->next(Carbon::MONDAY);
    }
}
