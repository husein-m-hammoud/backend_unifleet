<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\ProviderApiFailure;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Single source of truth for "is UNIFLEET healthy?".
 *
 * Read by three consumers so they can never disagree:
 *   • `php artisan unifleet:doctor`      — terminal, for us
 *   • `GET /api/health`                  — terse JSON, for an external uptime monitor
 *   • `GET /api/admin/diagnostics`       — full JSON, for the owner-only System page
 *
 * Design rule: EVERY check is individually wrapped. A diagnostics tool that
 * crashes when one subsystem is broken is useless precisely when it is needed,
 * so a failing check reports `fail` with the reason rather than throwing.
 *
 * Status vocabulary:
 *   ok   — verified working
 *   warn — works, but wrong for production (or a soft limit crossed)
 *   fail — broken, or stale past its threshold
 */
class SystemHealthService
{
    public const OK   = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** Cache key stamped every minute by the scheduler heartbeat (routes/console.php). */
    public const HEARTBEAT_KEY = 'scheduler_heartbeat';

    /**
     * Every check, grouped for display.
     *
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $checks = [
            ...$this->applicationChecks(),
            ...$this->databaseChecks(),
            ...$this->pipelineChecks(),
            ...$this->providerChecks(),
            ...$this->queueChecks(),
            ...$this->storageChecks(),
            ...$this->deliveryChecks(),
        ];

        return [
            'status'       => $this->worstOf($checks),
            'generated_at' => now()->toIso8601String(),
            'timezone'     => config('app.timezone'),
            'checks'       => $checks,
            'counts'       => $this->guard('counts', fn () => $this->dataCounts()),
        ];
    }

    /**
     * Terse payload for an external uptime monitor. Deliberately leaks nothing
     * about paths, versions or configuration — this endpoint is unauthenticated.
     */
    public function publicSummary(): array
    {
        $report = $this->report();

        return [
            'status'     => $report['status'],
            'checked_at' => $report['generated_at'],
            // Only the names of what is wrong, never the detail.
            'failing'    => array_values(array_map(
                fn ($c) => $c['key'],
                array_filter($report['checks'], fn ($c) => $c['status'] === self::FAIL),
            )),
        ];
    }

    // -------------------------------------------------------------------------
    // Application
    // -------------------------------------------------------------------------

    private function applicationChecks(): array
    {
        return [
            $this->guard('app_env', function () {
                $env   = config('app.env');
                $debug = (bool) config('app.debug');

                // APP_DEBUG=true in production leaks stack traces (and often
                // credentials) to anyone who triggers a 500.
                if ($env === 'production' && $debug) {
                    return $this->check('app_env', 'Environment', self::FAIL,
                        "{$env} / debug ON",
                        'APP_DEBUG=true in production exposes stack traces and env values to users. Set APP_DEBUG=false.');
                }

                if ($env !== 'production') {
                    return $this->check('app_env', 'Environment', self::WARN,
                        "{$env} / debug " . ($debug ? 'ON' : 'off'),
                        'Not running as production.');
                }

                return $this->check('app_env', 'Environment', self::OK, "{$env} / debug off");
            }),

            $this->guard('php', fn () => $this->check(
                'php', 'PHP', self::OK, PHP_VERSION,
                'Laravel ' . app()->version(),
            )),
        ];
    }

    // -------------------------------------------------------------------------
    // Database
    // -------------------------------------------------------------------------

    private function databaseChecks(): array
    {
        return [
            $this->guard('database', function () {
                $started = microtime(true);
                DB::connection()->getPdo();
                DB::select('select 1');
                $ms = round((microtime(true) - $started) * 1000);

                return $this->check('database', 'Database', self::OK,
                    config('database.default') . " · {$ms}ms",
                    'Connection established and a query returned.');
            }),

            $this->guard('migrations', function () {
                // Compare the migrations directory against the migrations table —
                // much cheaper than booting migrate:status.
                $ran = DB::table('migrations')->pluck('migration')->all();
                $files = collect(File::files(database_path('migrations')))
                    ->map(fn ($f) => $f->getFilenameWithoutExtension())
                    ->all();

                $pending = array_values(array_diff($files, $ran));

                if ($pending !== []) {
                    return $this->check('migrations', 'Migrations', self::FAIL,
                        count($pending) . ' pending',
                        'Run `php artisan migrate`. Pending: ' . implode(', ', array_slice($pending, 0, 5))
                        . (count($pending) > 5 ? ' …' : ''));
                }

                return $this->check('migrations', 'Migrations', self::OK, count($ran) . ' applied');
            }),
        ];
    }

    // -------------------------------------------------------------------------
    // Pipeline freshness — the check that would have caught the Sep 2026 outage
    // -------------------------------------------------------------------------

    private function pipelineChecks(): array
    {
        return [
            $this->guard('last_position', function () {
                $threshold = (float) Setting::get('poll_stale_threshold_hours', 6);

                $lastFix = DB::table('vehicle_positions as vp')
                    ->join('vehicles as v', 'v.id', '=', 'vp.vehicle_id')
                    ->where('v.status', 'active')
                    ->max('vp.time');

                if ($lastFix === null) {
                    return $this->check('last_position', 'Last GPS fix', self::FAIL, 'never',
                        'No stored positions for any active vehicle.');
                }

                $age = $this->ageHours($lastFix);

                return $this->check(
                    'last_position',
                    'Last GPS fix',
                    $age > $threshold ? self::FAIL : self::OK,
                    $this->human($age) . ' ago',
                    $age > $threshold
                        ? "Stale past the {$threshold}h threshold. Check the provider and that the scheduler is running."
                        : "Threshold {$threshold}h.",
                    ['at' => $this->iso($lastFix), 'age_hours' => round($age, 2)],
                );
            }),

            $this->guard('last_trip', function () {
                $lastTrip = DB::table('trips')->max('start_time');

                return $this->check('last_trip', 'Newest trip',
                    $lastTrip === null ? self::WARN : self::OK,
                    $lastTrip === null ? 'none' : $this->human($this->ageHours($lastTrip)) . ' ago',
                    'Trips arrive on the 5-minute full poll, so they lag positions.',
                    ['at' => $this->iso($lastTrip)],
                );
            }),

            $this->guard('scheduler', function () {
                // Stamped every minute by the heartbeat in routes/console.php.
                // A missing/old heartbeat means schedule:run (prod crontab) or
                // schedule:work (dev) is not running — nothing is polling.
                $beat = Cache::get(self::HEARTBEAT_KEY);

                if (! $beat) {
                    return $this->check('scheduler', 'Scheduler', self::FAIL, 'no heartbeat',
                        'Nothing is polling. Prod: add `* * * * * php artisan schedule:run` to crontab. Dev: run `php artisan schedule:work`.');
                }

                $ageMin = $this->ageHours($beat) * 60;

                // Allow generous slack: the heartbeat is per-minute, so anything
                // under 5 minutes is normal jitter.
                return $this->check('scheduler', 'Scheduler',
                    $ageMin > 5 ? self::FAIL : self::OK,
                    $this->human($ageMin / 60) . ' ago',
                    $ageMin > 5
                        ? 'The scheduler has stopped ticking — the poller is not running.'
                        : 'Heartbeat is current.',
                    ['at' => $this->iso($beat)],
                );
            }),
        ];
    }

    // -------------------------------------------------------------------------
    // Providers (Safee)
    // -------------------------------------------------------------------------

    private function providerChecks(): array
    {
        return [
            $this->guard('providers', function () {
                $providers = array_keys(config('services.safee.providers', []));

                if ($providers === []) {
                    return $this->check('providers', 'Safee providers', self::FAIL, 'none configured',
                        'No provider under config/services.safee.providers.');
                }

                $status  = self::OK;
                $details = [];
                $perProvider = [];

                foreach ($providers as $provider) {
                    $lastSuccess = ProviderApiFailure::lastSuccessAt($provider);

                    // Failures in the last hour are what "is it broken right now?"
                    // actually means.
                    $recent = ProviderApiFailure::where('provider', $provider)
                        ->where('occurred_at', '>=', now()->subHour())
                        ->count();

                    $latestFailure = ProviderApiFailure::where('provider', $provider)
                        ->latest('occurred_at')
                        ->first();

                    // Failing now = recent failures AND no success since the newest one.
                    $brokenNow = $recent > 0 && (
                        $lastSuccess === null
                        || ($latestFailure && Carbon::parse($lastSuccess)->lt($latestFailure->occurred_at))
                    );

                    if ($brokenNow) {
                        $status = self::FAIL;
                        $details[] = "{$provider}: {$recent} failure(s) in the last hour";
                    }

                    $perProvider[$provider] = [
                        'last_success_at'    => $lastSuccess,
                        'failures_last_hour' => $recent,
                        'failing_now'        => $brokenNow,
                        'last_failure'       => $latestFailure ? [
                            'at'          => $latestFailure->occurred_at?->toIso8601String(),
                            'kind'        => $latestFailure->kind,
                            'endpoint'    => $latestFailure->endpoint,
                            'status_code' => $latestFailure->status_code,
                            'message'     => $latestFailure->message,
                        ] : null,
                    ];
                }

                return $this->check('providers', 'Safee providers', $status,
                    implode(', ', $providers),
                    $details === [] ? 'No failures in the last hour.' : implode(' · ', $details),
                    ['providers' => $perProvider],
                );
            }),
        ];
    }

    // -------------------------------------------------------------------------
    // Queue
    // -------------------------------------------------------------------------

    private function queueChecks(): array
    {
        return [
            $this->guard('queue', function () {
                $driver = config('queue.default');

                if ($driver === 'sync') {
                    return $this->check('queue', 'Queue', self::WARN, 'sync',
                        'Jobs run inline. Fine for now, but a slow job blocks the request.');
                }

                if ($driver !== 'database') {
                    return $this->check('queue', 'Queue', self::OK, $driver,
                        'Depth not inspected for this driver.');
                }

                $pending = DB::table('jobs')->count();
                $failed  = DB::table('failed_jobs')->count();

                // A growing pending count with no worker is the classic silent
                // failure: notifications queue up and never send.
                $status = $failed > 0 || $pending > 100 ? self::WARN : self::OK;

                return $this->check('queue', 'Queue', $status,
                    "{$pending} pending · {$failed} failed",
                    $pending > 100
                        ? 'Backlog building — is `queue:work` running?'
                        : ($failed > 0 ? 'Inspect with `php artisan queue:failed`.' : 'Database queue, no backlog.'),
                    ['pending' => $pending, 'failed' => $failed],
                );
            }),
        ];
    }

    // -------------------------------------------------------------------------
    // Storage / logs
    // -------------------------------------------------------------------------

    private function storageChecks(): array
    {
        return [
            $this->guard('disk', function () {
                $path  = storage_path();
                $free  = @disk_free_space($path);
                $total = @disk_total_space($path);

                if (! $free || ! $total) {
                    return $this->check('disk', 'Disk', self::WARN, 'unknown',
                        'Could not read disk usage.');
                }

                $usedPct = round((1 - $free / $total) * 100);

                $status = match (true) {
                    $usedPct >= 90 => self::FAIL,
                    $usedPct >= 80 => self::WARN,
                    default        => self::OK,
                };

                return $this->check('disk', 'Disk', $status,
                    "{$usedPct}% used · " . $this->bytes($free) . ' free',
                    $usedPct >= 80 ? 'A full disk stops Postgres and the logs at the same time.' : null,
                    ['used_pct' => $usedPct, 'free_bytes' => (int) $free],
                );
            }),

            $this->guard('logs', function () {
                $channel = config('logging.default');
                $dir     = storage_path('logs');
                $files   = File::exists($dir) ? File::files($dir) : [];
                $bytes   = array_sum(array_map(fn ($f) => $f->getSize(), $files));

                // A single unrotated laravel.log grows without bound and becomes
                // unsearchable — exactly when you need to search it.
                // `logging.channels.stack.channels` is already an array in
                // config (the config file explodes LOG_STACK itself), so cast
                // rather than re-exploding a string.
                $stack  = (array) config('logging.channels.stack.channels', []);
                $single = $channel === 'single' || in_array('single', $stack, true);

                $status = match (true) {
                    $bytes > 500 * 1024 * 1024 => self::FAIL,
                    $single || $bytes > 100 * 1024 * 1024 => self::WARN,
                    default => self::OK,
                };

                return $this->check('logs', 'Logs', $status,
                    $this->bytes($bytes) . " · channel {$channel}",
                    $single
                        ? 'Unrotated single-file logging. Set LOG_CHANNEL=daily (14-day retention) so logs stay searchable and bounded.'
                        : null,
                    ['bytes' => $bytes, 'files' => count($files), 'channel' => $channel],
                );
            }),
        ];
    }

    // -------------------------------------------------------------------------
    // Alert delivery
    // -------------------------------------------------------------------------

    private function deliveryChecks(): array
    {
        return [
            $this->guard('mail', function () {
                $mailer = config('mail.default');

                if ($mailer === 'log') {
                    return $this->check('mail', 'Mail', self::WARN, 'log',
                        'Emails are written to the log, not sent. Nobody receives credentials, resets or report digests.');
                }

                return $this->check('mail', 'Mail', self::OK, $mailer,
                    'Configured — this check does not send a test message.');
            }),

            $this->guard('ops_alerts', function () {
                $channels = OpsNotifier::configuredChannels();

                if ($channels === []) {
                    return $this->check('ops_alerts', 'Ops alerting', self::FAIL, 'none',
                        'Nothing will tell you the pipeline died — it is only written to the log. Set OPS_ALERT_EMAIL and a real MAIL_MAILER (not `log`).');
                }

                return $this->check('ops_alerts', 'Ops alerting', self::OK,
                    implode(' + ', $channels),
                    'Stale-pipeline alerts will be delivered here.');
            }),

            $this->guard('open_alerts', function () {
                $open = Alert::whereNull('resolved_at')
                    ->selectRaw('type, count(*) as c')
                    ->groupBy('type')
                    ->pluck('c', 'type')
                    ->all();

                $total = array_sum($open);
                $stale = $open['poll_stale'] ?? 0;

                return $this->check('open_alerts', 'Open alerts',
                    $stale > 0 ? self::FAIL : self::OK,
                    (string) $total,
                    $stale > 0
                        ? 'A poll_stale alert is open — telemetry is not arriving.'
                        : 'By type: ' . (json_encode($open) ?: '{}'),
                    ['by_type' => $open],
                );
            }),
        ];
    }

    // -------------------------------------------------------------------------
    // Data counts (context, not pass/fail)
    // -------------------------------------------------------------------------

    private function dataCounts(): array
    {
        return [
            'vehicles_active' => DB::table('vehicles')->where('status', 'active')->count(),
            'vehicles_total'  => DB::table('vehicles')->count(),
            'positions'       => DB::table('vehicle_positions')->count(),
            'trips'           => DB::table('trips')->count(),
            'alerts_open'     => DB::table('alerts')->whereNull('resolved_at')->count(),
            'zones'           => DB::table('zones')->count(),
            'users_active'    => DB::table('users')->where('status', 'active')->count(),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function check(string $key, string $label, string $status, ?string $value = null, ?string $detail = null, array $extra = []): array
    {
        return array_merge([
            'key'    => $key,
            'label'  => $label,
            'status' => $status,
            'value'  => $value,
            'detail' => $detail,
        ], $extra === [] ? [] : ['extra' => $extra]);
    }

    /**
     * Run one check, converting any throwable into a `fail` row. This is what
     * keeps the whole report readable when a single subsystem is down.
     */
    private function guard(string $key, callable $fn): array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return $this->check($key, ucfirst(str_replace('_', ' ', $key)), self::FAIL, 'check failed',
                get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 300));
        }
    }

    private function worstOf(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        return match (true) {
            in_array(self::FAIL, $statuses, true) => self::FAIL,
            in_array(self::WARN, $statuses, true) => self::WARN,
            default                               => self::OK,
        };
    }

    private function ageHours(string $timestamp): float
    {
        return abs(Carbon::parse($timestamp)->diffInMinutes(now())) / 60;
    }

    private function iso(?string $timestamp): ?string
    {
        return $timestamp === null ? null : Carbon::parse($timestamp)->toIso8601String();
    }

    private function human(float $hours): string
    {
        if ($hours < 1 / 60) {
            return 'seconds';
        }

        if ($hours < 1) {
            return round($hours * 60) . ' min';
        }

        if ($hours < 48) {
            return round($hours, 1) . ' h';
        }

        return round($hours / 24, 1) . ' days';
    }

    private function bytes(float $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return round($bytes, $unit === 'B' ? 0 : 1) . " {$unit}";
            }
            $bytes /= 1024;
        }

        return (string) $bytes;
    }
}
