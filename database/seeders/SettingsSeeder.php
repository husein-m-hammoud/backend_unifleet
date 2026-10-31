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
                'description' => 'Intended retention window for GPS position records. NOT YET ENFORCED - no pruning job reads this value, so position history currently grows without bound. Kept here so the policy is recorded and the job has a value to read once it exists.',
                'group'       => 'data',
            ],

            // ── Alerts (continued) ────────────────────────────────
            [
                'key'         => 'low_fuel_pct',
                'value'       => '20',
                'type'        => 'integer',
                'label'       => 'Low Fuel Threshold',
                'description' => 'Fuel percentage below which a low-fuel alert is raised. Note: the provider has never returned fuel data, so this rule cannot currently fire.',
                'group'       => 'alerts',
            ],
            [
                'key'         => 'alert_cooldown',
                'value'       => '30',
                'type'        => 'integer',
                'label'       => 'Alert Cooldown',
                'description' => 'Minutes before the same alert can be raised again for the same vehicle. Stops one ongoing problem producing hundreds of duplicate rows.',
                'group'       => 'alerts',
            ],

            // ── Monitoring ────────────────────────────────────────
            [
                'key'         => 'poll_stale_threshold_hours',
                'value'       => '6',
                'type'        => 'integer',
                'label'       => 'Telemetry Stale Threshold',
                'description' => 'Hours without a fresh GPS fix fleet-wide before the health check reports the pipeline as stale. This is the check that would have caught the September 2026 outage on day one instead of day fourteen.',
                'group'       => 'monitoring',
            ],

            // ── Fleet health scoring ──────────────────────────────
            // These three are weights in a weighted average and should sum to 1.
            [
                'key'         => 'health_weight_safety',
                'value'       => '0.4',
                'type'        => 'float',
                'label'       => 'Health Weight: Safety',
                'description' => 'Weight of the safety pillar in the overall fleet health score. The three health weights should sum to 1.0.',
                'group'       => 'scoring',
            ],
            [
                'key'         => 'health_weight_movement',
                'value'       => '0.3',
                'type'        => 'float',
                'label'       => 'Health Weight: Movement',
                'description' => 'Weight of the movement pillar in the overall fleet health score. The three health weights should sum to 1.0.',
                'group'       => 'scoring',
            ],
            [
                'key'         => 'health_weight_utilization',
                'value'       => '0.3',
                'type'        => 'float',
                'label'       => 'Health Weight: Utilization',
                'description' => 'Weight of the utilization pillar in the overall fleet health score. The three health weights should sum to 1.0.',
                'group'       => 'scoring',
            ],
            [
                'key'         => 'risk_weight_overspeed',
                'value'       => '8',
                'type'        => 'integer',
                'label'       => 'Risk Weight: Over-speed',
                'description' => 'Penalty points applied to a vehicle risk score per over-speed event.',
                'group'       => 'scoring',
            ],
            [
                'key'         => 'risk_weight_idle',
                'value'       => '40',
                'type'        => 'integer',
                'label'       => 'Risk Weight: Excessive Idling',
                'description' => 'Penalty points applied to a vehicle risk score per excessive-idling event.',
                'group'       => 'scoring',
            ],

            // ── Analytics & anomaly detection ─────────────────────
            [
                'key'         => 'trend_min_prev_trips',
                'value'       => '5',
                'type'        => 'integer',
                'label'       => 'Trend Minimum Trips',
                'description' => 'Minimum trips in the previous period before a trend is reported. Prevents "up 400%" from a base of one trip.',
                'group'       => 'analytics',
            ],
            [
                'key'         => 'trend_min_prev_ratio',
                'value'       => '0.2',
                'type'        => 'float',
                'label'       => 'Trend Minimum Change',
                'description' => 'Minimum proportional change (0.2 = 20%) before a trend is worth reporting, so normal week-to-week noise is not presented as a finding.',
                'group'       => 'analytics',
            ],
            [
                'key'         => 'anomaly_min_vehicles',
                'value'       => '8',
                'type'        => 'integer',
                'label'       => 'Anomaly Minimum Fleet Size',
                'description' => 'Fewest vehicles required before anomaly detection runs. Below this there is no meaningful "normal" to compare against.',
                'group'       => 'analytics',
            ],
            [
                'key'         => 'anomaly_contamination',
                'value'       => '0.1',
                'type'        => 'float',
                'label'       => 'Anomaly Contamination',
                'description' => 'Expected proportion of outliers (0.1 = 10%) passed to the Isolation Forest. Raise it to surface more anomalies, lower it to surface only the clearest.',
                'group'       => 'analytics',
            ],
            [
                'key'         => 'anomaly_trees',
                'value'       => '120',
                'type'        => 'integer',
                'label'       => 'Anomaly Forest Size',
                'description' => 'Number of trees in the Isolation Forest. Higher is more stable but slower.',
                'group'       => 'analytics',
            ],
            [
                'key'         => 'anomaly_max_findings',
                'value'       => '4',
                'type'        => 'integer',
                'label'       => 'Anomaly Maximum Findings',
                'description' => 'Most anomalies shown on the AI Insights page at once, so the report stays readable.',
                'group'       => 'analytics',
            ],

            // Report recipients used to live here as `report_email_recipients`.
            // Superseded by per-event recipients (`email_events` + the Email
            // notifications dialog), which cover To and CC for every email.
        ];

        foreach ($defaults as $setting) {
            Setting::firstOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
