<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Safee poller — split into two cadences so the live map stays near-real-time
// without waiting on the slow per-vehicle telemetry:
//
//   • Live positions + alert detection — every 30s (fetching last-state for the
//     whole fleet takes ~2s). This is what makes the map "live".
//   • Heavy sync (reference data, trips, fuel/speed/weight) — every 5 min.
//
// Both poll all configured providers (Alrakeen now, saudiX later).
Schedule::command('safee:poll --live-only')->everyThirtySeconds()->withoutOverlapping();
Schedule::command('safee:poll')->everyFiveMinutes()->withoutOverlapping();

// Freshness watchdog — raises a self-resolving `poll_stale` alert if telemetry
// goes stale (dead scheduler, provider outage). Guards against the pipeline
// silently dying unnoticed (as it did for 14 days in Sep 2026).
Schedule::command('safee:health')->hourly();

// Emailed fleet report digests — recipients come from the `report_email_recipients`
// setting (no-op when unset). Times are Asia/Riyadh; the weekly digest runs Sunday
// (start of the Saudi work week) and covers the previous 7 days.
Schedule::command('reports:email daily')->dailyAt('06:00')->timezone('Asia/Riyadh');
Schedule::command('reports:email weekly')->weeklyOn(0, '06:30')->timezone('Asia/Riyadh');
