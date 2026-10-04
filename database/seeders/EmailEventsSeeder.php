<?php

namespace Database\Seeders;

use App\Models\EmailEvent;
use Illuminate\Database\Seeder;

/**
 * Creates one row per known email event, idempotently.
 *
 * Seeds `ops_alert.to` from the legacy OPS_ALERT_EMAIL env var so an install
 * configured before per-event recipients existed keeps alerting.
 *
 * (The old `report_email_recipients` setting was retired in migration
 * 2026_10_04_000002, which copies any value it held into the report events.)
 */
class EmailEventsSeeder extends Seeder
{
    public function run(): void
    {
        $legacyOps = EmailEvent::normalise((string) config('services.ops.email'));

        foreach (array_keys(EmailEvent::EVENTS) as $event) {
            $to = $event === 'ops_alert' ? $legacyOps : null;

            // firstOrCreate, so re-running never overwrites what an admin has set.
            EmailEvent::firstOrCreate(
                ['event' => $event],
                ['enabled' => true, 'to' => $to, 'cc' => null],
            );
        }
    }
}
