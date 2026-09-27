<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Vehicle;
use App\Notifications\ReportDigest;
use App\Services\AnalyticsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Emails a daily or weekly fleet report digest to the addresses configured in the
 * `report_email_recipients` setting. Scheduled from routes/console.php; a no-op
 * (exit 0) when no recipients are set. The report covers the WHOLE fleet — it is
 * not scoped to any user.
 */
class EmailReportCommand extends Command
{
    protected $signature = 'reports:email {period : daily or weekly}';

    protected $description = 'Email a daily or weekly fleet report digest to the configured recipients';

    public function handle(AnalyticsService $analytics): int
    {
        $period = strtolower((string) $this->argument('period'));
        if (! in_array($period, ['daily', 'weekly'], true)) {
            $this->error("Period must be 'daily' or 'weekly'.");
            return self::INVALID;
        }

        $recipients = collect(preg_split('/[,;\s]+/', (string) Setting::get('report_email_recipients', '')))
            ->map(fn ($e) => trim($e))
            ->filter(fn ($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values();

        if ($recipients->isEmpty()) {
            $this->info('No valid recipients in report_email_recipients — nothing to send.');
            return self::SUCCESS;
        }

        // Window in the client's timezone: daily = yesterday; weekly = the previous
        // 7 full days ending yesterday. Converted to UTC for the query (DB is UTC).
        $tz    = 'Asia/Riyadh';
        $end   = Carbon::now($tz)->subDay()->endOfDay();
        $start = $period === 'daily'
            ? Carbon::now($tz)->subDay()->startOfDay()
            : Carbon::now($tz)->subDays(7)->startOfDay();

        // Whole fleet — no user scoping for the automated report.
        $vehicleIds = Vehicle::query()->pluck('id');
        $payload    = $analytics->insights($vehicleIds, $start->clone()->utc(), $end->clone()->utc(), $vehicleIds->count());

        $type  = $period === 'daily' ? 'Daily' : 'Weekly';
        $label = $period === 'daily'
            ? $start->format('j M Y')
            : $start->format('j M') . ' – ' . $end->format('j M Y');
        $url = rtrim((string) config('app.frontend_url'), '/')
            . '/dashboard/ai?from=' . $start->format('Y-m-d') . '&to=' . $end->format('Y-m-d');

        $sent = 0;
        foreach ($recipients as $addr) {
            try {
                Notification::route('mail', $addr)->notify(new ReportDigest($payload, $type, $label, $url));
                $sent++;
            } catch (\Throwable $e) {
                Log::warning("[reports:email] failed for {$addr}: {$e->getMessage()}");
            }
        }

        $this->info("Sent {$type} report ({$label}) to {$sent}/{$recipients->count()} recipient(s).");
        return self::SUCCESS;
    }
}
