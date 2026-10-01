<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\PartLifetimeService;
use App\Services\PmSchedulingService;
use Illuminate\Console\Command;

/**
 * The cron-driven equivalent of what TaskController::pmSchedule() does as a
 * side effect of every page load — weekly/monthly Task Library recurrence
 * and Part Lifetime at-risk auto-scheduling, for every active branch, not
 * just the ones someone happens to open the PM Calendar for. Both
 * underlying calls are already idempotent (ensureRecurringSchedules() checks
 * for an existing occurrence before creating one; autoScheduleAllAtRisk()
 * does the same), so running this on a schedule alongside the page-load
 * trigger is safe — whichever runs first for a given period wins, the other
 * is a no-op.
 */
class GeneratePmSchedules extends Command
{
    protected $signature = 'pm:generate-schedules';

    protected $description = 'Generate due recurring PM Task Library occurrences and Part Lifetime at-risk tasks for every active branch';

    public function handle(PmSchedulingService $scheduling, PartLifetimeService $lifetime): int
    {
        $branches = Branch::where('is_active', true)->get();

        foreach ($branches as $branch) {
            $scheduling->ensureRecurringSchedules($branch);
            $lifetime->autoScheduleAllAtRisk($branch->id);
        }

        $this->info("PM schedules checked for {$branches->count()} active branch(es).");

        return self::SUCCESS;
    }
}
