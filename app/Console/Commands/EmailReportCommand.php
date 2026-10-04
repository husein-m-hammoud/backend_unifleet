<?php

namespace App\Console\Commands;

use App\Models\EmailEvent;
use App\Models\Vehicle;
use App\Notifications\ReportDigest;
use App\Services\AnalyticsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Emails a daily or weekly fleet report digest to the recipients configured for
 * the `report_daily` / `report_weekly` email events (Settings -> Email
 * notifications). Scheduled from routes/console.php; a no-op (exit 0) when the
 * event is switched off or has no recipients. The report covers the WHOLE fleet —
 * it is not scoped to any user.
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

        // Recipients come from the per-event email config. These events have no
        // natural recipient, so an empty `to` means "don't send".
        $event    = "report_{$period}";
        $resolved = EmailEvent::recipients($event);

        if (! $resolved['enabled']) {
            $this->info("Email event [{$event}] is disabled — nothing to send.");
            return self::SUCCESS;
        }

        if ($resolved['to'] === []) {
            $this->info("No recipients configured for [{$event}] — nothing to send.");
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

        // ONE email addressed to every recipient, with the configured CC — not a
        // separate email each, so recipients can see they share the report and a
        // CC actually behaves like a CC.
        try {
            Notification::route('mail', $resolved['to'])
                ->notify(new ReportDigest($payload, $type, $label, $url));
        } catch (\Throwable $e) {
            Log::warning("[reports:email] {$event} failed: {$e->getMessage()}");
            $this->error("Send failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $cc = $resolved['cc'] === [] ? '' : ' (cc ' . implode(', ', $resolved['cc']) . ')';
        $this->info("Sent {$type} report ({$label}) to " . implode(', ', $resolved['to']) . $cc . '.');

        return self::SUCCESS;
    }
}
