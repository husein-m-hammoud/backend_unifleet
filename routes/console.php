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
