<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Poller freshness watchdog.
 *
 * The pipeline silently went dark for 14 days in Sep 2026 (provider outage +
 * a dead scheduler) with no warning — every downstream feature (map, dashboard,
 * AI insights) served stale data. This command is the guard against a repeat:
 * it measures how old the freshest stored fix is and raises a self-resolving
 * `poll_stale` alert (via the existing Alert plumbing) when it crosses a
 * threshold. Schedule it hourly; also run it by hand any time.
 *
 * It checks DATA freshness, not process liveness — if positions are hours old,
 * the insights ARE stale regardless of the cause, which is exactly the thing
 * worth alerting on.
 */
class PollHealthCommand extends Command
{
    protected $signature = 'safee:health
                            {--hours= : Staleness threshold in hours (overrides the poll_stale_threshold_hours setting)}';

    protected $description = 'Alert if the poller has gone stale (no fresh telemetry) — guards against the pipeline silently dying.';

    public function handle(): int
    {
        $threshold = (float) ($this->option('hours') ?? Setting::get('poll_stale_threshold_hours', 6));

        // Freshest stored fix across active vehicles — the data every downstream
        // feature depends on.
        $lastFix = DB::table('vehicle_positions as vp')
            ->join('vehicles as v', 'v.id', '=', 'vp.vehicle_id')
            ->where('v.status', 'active')
            ->max('vp.time');

        $newestTrip = DB::table('trips')->max('start_time');

        if ($lastFix === null) {
            return $this->flagStale($threshold, 'no stored positions for any active vehicle', null, $newestTrip);
        }

        $ageHours = abs(Carbon::parse($lastFix)->diffInMinutes(now())) / 60;

        if ($ageHours > $threshold) {
            return $this->flagStale($threshold, $this->human($ageHours) . ' since the last fix', $lastFix, $newestTrip, $ageHours);
        }

        $this->recover();
        $this->info("Poller healthy — last fix {$this->human($ageHours)} ago (threshold {$threshold}h).");

        return self::SUCCESS;
    }

    /**
     * Raise (or refresh) the open poll_stale alert and log a warning.
     */
    private function flagStale(float $threshold, string $reason, ?string $lastFix, ?string $newestTrip, ?float $ageHours = null): int
    {
        $meta = [
            'reason'          => $reason,
            'last_fix'        => $lastFix,
            'newest_trip'     => $newestTrip,
            'age_hours'       => $ageHours !== null ? round($ageHours, 1) : null,
            'threshold_hours' => $threshold,
            'checked_at'      => now()->toIso8601String(),
        ];

        // One open alert at a time — refresh the lag on the existing one rather
        // than spamming a new alert every run.
        $open = Alert::where('type', 'poll_stale')->whereNull('resolved_at')->latest('triggered_at')->first();

        if ($open) {
            $open->update(['meta' => $meta]);
        } else {
            Alert::create([
                'vehicle_id'   => null,          // fleet-wide, not tied to a vehicle
                'type'         => 'poll_stale',
                'triggered_at' => now(),
                'meta'         => $meta,
            ]);
        }

        Log::warning("[Safee] Poller appears STALE: {$reason} (threshold {$threshold}h). Is schedule:work running?", $meta);
        $this->error("STALE: {$reason} (threshold {$threshold}h). Newest trip: " . ($newestTrip ?? 'none') . '.');

        return self::FAILURE;
    }

    /**
     * Data is fresh again — resolve any open poll_stale alert and stamp it
     * "back online" (mirrors how the no_signal alert recovers).
     */
    private function recover(): void
    {
        Alert::where('type', 'poll_stale')->whereNull('resolved_at')->get()->each(function (Alert $a) {
            $a->update([
                'resolved_at' => now(),
                'meta'        => array_merge($a->meta ?? [], [
                    'back_online' => true,
                    'restored_at' => now()->toIso8601String(),
                ]),
            ]);
        });
    }

    private function human(float $hours): string
    {
        return $hours < 1 ? round($hours * 60) . ' min' : round($hours, 1) . ' h';
    }
}
