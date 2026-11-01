<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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
// Both poll all configured providers (Alrakeen + DSCO).
Schedule::command('safee:poll --live-only')->everyThirtySeconds()->withoutOverlapping();
// Every 15 minutes, NOT 5. Measured 2026-11-01: a full poll over 218 active
// vehicles takes 6m18s, because steps 1-7 include two sequential API calls per
// vehicle (trips + speed). On a 5-minute schedule the job could never finish
// before the next tick was due, so withoutOverlapping() silently dropped most
// runs - the poll looked scheduled four times more often than it actually ran.
// 15 minutes gives a ~6-minute job room to finish, idle, and start clean.
//
// Nothing a user sees is affected. Live positions - the only data that needs to
// be fresh - come from the --live-only poll above, every 30 seconds. What this
// job fetches (sites, categories, geofences, drivers, vehicle records, trips,
// speed summaries) does not change minute to minute. The trackers themselves
// only emit a new fix about every 10 minutes (median), so even the 30s live
// poll already asks far faster than the devices answer.
Schedule::command('safee:poll')->everyFifteenMinutes()->withoutOverlapping();

// Scheduler heartbeat — stamps "the scheduler ticked" once a minute. This is
// how `unifleet:doctor` and the System page can tell "the poller found nothing"
// apart from "nothing is running at all", which is the distinction that made the
// Sep 2026 outage invisible for 14 days. Cheap: one cache write per minute.
Schedule::call(function () {
    Cache::put(
        \App\Services\SystemHealthService::HEARTBEAT_KEY,
        now()->toIso8601String(),
        now()->addDays(7),
    );
})->everyMinute()->name('scheduler-heartbeat')->withoutOverlapping();

// Freshness watchdog — raises a self-resolving `poll_stale` alert if telemetry
// goes stale (dead scheduler, provider outage) AND notifies us via OpsNotifier.
// Guards against the pipeline silently dying unnoticed (as it did for 14 days in
// Sep 2026).
Schedule::command('safee:health')->hourly();

// Keep provider_api_failures bounded (30-day retention).
// Expiring tokens (config/sanctum.php) leaves the dead rows behind; drop the
// ones that have been expired for over a day so personal_access_tokens does not
// grow without bound.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

Schedule::command('unifleet:doctor --prune')->weeklyOn(1, '04:00')->timezone('Asia/Riyadh');

// Emailed fleet report digests — recipients come from the `report_email_recipients`
// setting (no-op when unset). Times are Asia/Riyadh; the weekly digest runs Sunday
// (start of the Saudi work week) and covers the previous 7 days.
Schedule::command('reports:email daily')->dailyAt('06:00')->timezone('Asia/Riyadh');
Schedule::command('reports:email weekly')->weeklyOn(0, '06:30')->timezone('Asia/Riyadh');
