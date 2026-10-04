<?php

namespace Database\Seeders;

use App\Models\EmailEvent;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Creates one row per known email event, idempotently.
 *
 * Also migrates the two pre-existing single-value configs into the new per-event
 * rows so nothing silently stops being delivered:
 *   report_email_recipients setting  -> report_daily.to + report_weekly.to
 *   OPS_ALERT_EMAIL env               -> ops_alert.to
 */
class EmailEventsSeeder extends Seeder
{
    public function run(): void
    {
        $legacyReports = EmailEvent::normalise((string) Setting::get('report_email_recipients', ''));
        $legacyOps     = EmailEvent::normalise((string) config('services.ops.email'));

        foreach (array_keys(EmailEvent::EVENTS) as $event) {
            $to = match ($event) {
                'report_daily', 'report_weekly' => $legacyReports,
                'ops_alert'                     => $legacyOps,
                default                         => null,
            };

            // firstOrCreate, so re-running never overwrites what an admin has set.
            EmailEvent::firstOrCreate(
                ['event' => $event],
                ['enabled' => true, 'to' => $to, 'cc' => null],
            );
        }
    }
}
