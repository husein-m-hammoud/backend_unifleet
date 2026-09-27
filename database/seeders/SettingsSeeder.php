<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // ── Alerts & Thresholds ───────────────────────────────
            [
                'key'         => 'speed_limit',
                'value'       => '120',
                'type'        => 'integer',
                'label'       => 'Default Speed Limit',
                'description' => 'Fallback maximum speed in km/h, used when a vehicle has no type-specific limit and is not inside a site that sets its own limit. Priority: site limit > vehicle-type limit > this default.',
                'group'       => 'alerts',
            ],
            [
                'key'         => 'idle_threshold',
                'value'       => '10',
                'type'        => 'integer',
                'label'       => 'Idle Time Threshold',
                'description' => 'Minutes a vehicle can idle before triggering an excessive-idling alert.',
                'group'       => 'alerts',
            ],
            [
                'key'         => 'unauthorized_zone_threshold',
                'value'       => '15',
                'type'        => 'integer',
                'label'       => 'Unauthorized Zone Threshold',
                'description' => 'Minutes a vehicle may dwell inside a zone it is not assigned to before an unauthorized-zone alert is raised. Passing through for less than this is ignored.',
                'group'       => 'alerts',
            ],
            [
                'key'         => 'no_signal_threshold_hours',
                'value'       => '24',
                'type'        => 'integer',
                'label'       => 'No Signal Threshold',
                'description' => 'Hours a vehicle can go without reporting a fresh GPS fix before it is flagged "No signal" (dead/offline tracker) and an alert is raised. Below this it is simply treated as offline.',
                'group'       => 'alerts',
            ],

            // ── Map & Tracking ────────────────────────────────────
            [
                'key'         => 'default_path_days',
                'value'       => '7',
                'type'        => 'integer',
                'label'       => 'Default Path Window',
                'description' => 'Default number of days of GPS history shown as a path on the map.',
                'group'       => 'map',
            ],
            [
                'key'         => 'map_refresh_interval',
                'value'       => '30',
                'type'        => 'integer',
                'label'       => 'Map Refresh Interval',
                'description' => 'How often (seconds) the live map polls for updated positions.',
                'group'       => 'map',
            ],

            // ── Data & Retention ──────────────────────────────────
            [
                'key'         => 'position_retention_days',
                'value'       => '90',
                'type'        => 'integer',
                'label'       => 'Position History Retention',
                'description' => 'Number of days to keep GPS position records. Older rows are pruned automatically.',
                'group'       => 'data',
            ],

            // ── Reports ───────────────────────────────────────────
            [
                'key'         => 'report_email_recipients',
                'value'       => '',
                'type'        => 'string',
                'label'       => 'Report Email Recipients',
                'description' => 'Comma-separated email addresses that receive the automated daily and weekly fleet report digests. Leave blank to disable scheduled report emails.',
                'group'       => 'reports',
            ],
        ];

        foreach ($defaults as $setting) {
            Setting::firstOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
