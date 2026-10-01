<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requires the Laravel scheduler to actually be running (`schedule:run` every
// minute) — in Herd, toggle "Scheduler" on for this site; in production, a
// system cron entry. Without that, this definition alone does nothing —
// TaskController::pmSchedule()'s page-load trigger remains the fallback.
Schedule::command('pm:generate-schedules')->dailyAt('00:10');
