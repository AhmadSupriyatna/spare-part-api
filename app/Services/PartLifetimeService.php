<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\PartInstallation;
use App\Models\ProductionLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
     * Checks every active installation on this line's equipment and
     * auto-creates a PM task for any that just crossed the at-risk
     * threshold and doesn't already have one pending/in progress — called
     * after a runtime-hours update, since that's the only thing that can
     * push percentUsed() up for parts on this specific line.
     */
    public function autoScheduleAtRisk(ProductionLine $line): void
    {
        $installations = PartInstallation::query()
            ->whereNull('removed_at')
            ->whereHas('equipment.machine', fn ($q) => $q->where('line_id', $line->id))
            ->with(['part', 'partUnit.part', 'partUnit.installations'])
            ->get()
            ->filter(fn (PartInstallation $installation) => ($installation->percentUsed() ?? 0) >= self::AT_RISK_THRESHOLD_PERCENT);

        $this->createMissingLifetimeTasks($installations);
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
     * remaining hours ÷ avg weekly operating hours (Settings, default 85) =
     * weeks until the part is projected to be fully worn; the due date is
     * the next Monday on or after that point (rounds forward, never lands
     * mid-week), and never earlier than the coming Monday even if the
     * calculation lands in the past (e.g. remaining hours already at 0).
     */
    public function computeAutoScheduleDueDate(PartInstallation $installation): Carbon
    {
        $remainingHours = $installation->partUnit?->remainingHours() ?? 0;
        $avgWeeklyHours = CompanySetting::current()->avg_weekly_operating_hours ?: 85;
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
