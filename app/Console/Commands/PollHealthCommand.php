<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\Setting;
use App\Services\OpsNotifier;
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

        // Detection without delivery is what made the Sep 2026 outage last 14
        // days. Dedupe on the alert (not the run) so an ongoing outage notifies
        // every 6 hours rather than every hour.
        $delivered = OpsNotifier::send(
            'Telemetry has gone stale',
            "UNIFLEET is not receiving fresh data.\n\n"
            . "Reason: {$reason}\n"
            . 'Last fix: ' . ($lastFix ?? 'never') . "\n"
            . 'Newest trip: ' . ($newestTrip ?? 'none') . "\n"
            . "Threshold: {$threshold}h\n\n"
            . 'Check: is `schedule:run` in crontab, and is the provider reachable? '
            . 'Run `php artisan unifleet:doctor` for the full picture.',
            dedupeKey: 'poll_stale',
            cooldownMinutes: 360,
        );

        if ($delivered !== []) {
            $this->line('Notified via ' . implode(' + ', $delivered) . '.');
        }

        return self::FAILURE;
    }

    /**
     * Data is fresh again — resolve any open poll_stale alert and stamp it
     * "back online" (mirrors how the no_signal alert recovers).
     */
    private function recover(): void
    {
        $resolved = Alert::where('type', 'poll_stale')->whereNull('resolved_at')->get();

        $resolved->each(function (Alert $a) {
            $a->update([
                'resolved_at' => now(),
                'meta'        => array_merge($a->meta ?? [], [
                    'back_online' => true,
                    'restored_at' => now()->toIso8601String(),
                ]),
            ]);
        });

        // Only announce recovery if we actually announced a problem — otherwise
        // every healthy hourly run would send an all-clear.
        if ($resolved->isNotEmpty()) {
            OpsNotifier::send(
                'Telemetry is back',
                "UNIFLEET is receiving fresh data again. The stale-pipeline alert has been resolved.",
            );

            // Clear the cooldown so the next genuine outage notifies immediately.
            try {
                \Illuminate\Support\Facades\Cache::forget('ops_notice_poll_stale');
            } catch (\Throwable $e) {
                // Non-fatal.
            }
        }
    }

    private function human(float $hours): string
    {
        return $hours < 1 ? round($hours * 60) . ' min' : round($hours, 1) . ' h';
    }
}
