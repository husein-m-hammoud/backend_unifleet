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
                'label'       => 'Speed Limit',
                'description' => 'Maximum allowed speed in km/h. Vehicles exceeding this will trigger an over-speed alert.',
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
                'key'         => 'low_fuel_pct',
                'value'       => '20',
                'type'        => 'integer',
                'label'       => 'Low Fuel Warning',
                'description' => 'Fuel percentage below which a low-fuel alert is triggered.',
                'group'       => 'alerts',
            ],
            [
                'key'         => 'alert_cooldown',
                'value'       => '30',
                'type'        => 'integer',
                'label'       => 'Alert Cooldown',
                'description' => 'Minimum minutes between repeated alerts of the same type for the same vehicle.',
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
            [
                'key'         => 'show_offline_vehicles',
                'value'       => 'true',
                'type'        => 'boolean',
                'label'       => 'Show Offline Vehicles',
                'description' => 'Whether to display vehicles with no recent GPS fix on the map.',
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
        ];

        foreach ($defaults as $setting) {
            Setting::firstOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
