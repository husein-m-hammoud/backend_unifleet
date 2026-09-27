<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The "AI Insights" engine — deterministic analytics + a rule-based narrative
 * generator. Reads like AI wrote it, costs nothing per request, never sends
 * fleet data off-server. Built entirely on FleetMetricsService so Reports and
 * the AI page can never disagree on the numbers.
 *
 * Fuel is intentionally excluded from this version.
 */
class AnalyticsService
{
    public function __construct(
        private FleetMetricsService $metrics,
        private FleetAnomalyService $anomaly,
    ) {}

    /** Health-score component weights (admin-tunable later via settings). */
    private function weights(): array
    {
        return [
            'safety'       => (float) Setting::get('health_weight_safety', 0.4),
            'utilization'  => (float) Setting::get('health_weight_utilization', 0.3),
            'movement'     => (float) Setting::get('health_weight_movement', 0.3),
        ];
    }

    /**
     * Everything the AI Reports page needs, for one scoped vehicle set + period.
     *
     * @param  Collection<int>|array<int>  $vehicleIds
     */
    public function insights($vehicleIds, Carbon $from, Carbon $to, int $totalVehicles): array
    {
        $ids = collect($vehicleIds);

        // Previous equal-length window, for trend arrows.
        $lengthSec = max(1, $to->getTimestamp() - $from->getTimestamp());
        $prevTo    = (clone $from)->subSecond();
        $prevFrom  = (clone $prevTo)->subSeconds($lengthSec);

        $summary     = $this->metrics->summary($ids, $from, $to);
        $prevSummary = $this->metrics->summary($ids, $prevFrom, $prevTo);
        $perVehicle  = $this->metrics->perVehicle($ids, $from, $to);
        $zones       = $this->metrics->zoneActivity($ids, $from, $to);
        $idleRanking = $this->metrics->idleRanking($ids, $from, $to);
        $unused      = $this->metrics->unusedVehicles($ids, $from, $to);
        $efficiency  = $this->metrics->efficiency($ids, $from, $to);

        // A trend is only trustworthy when the comparison window has a
        // *meaningful* amount of activity. A near-empty previous period (e.g. a
        // week the poller was down) produces a tiny, unstable baseline and makes
        // the arrow swing wildly. Require the previous window to have at least an
        // absolute floor AND a fair share of the current window's trips; otherwise
        // suppress the arrows and label it "first tracked period".
        $minPrevTrips = max(
            (int) Setting::get('trend_min_prev_trips', 5),
            (int) round($summary['trips'] * (float) Setting::get('trend_min_prev_ratio', 0.2)),
        );
        $hasPrev = $prevSummary['trips'] >= $minPrevTrips;

        $health          = $this->healthScore($summary, $totalVehicles);
        $prevHealth      = $this->healthScore($prevSummary, $totalVehicles);
        $health['trend'] = $hasPrev ? $health['score'] - $prevHealth['score'] : null;

        $riskRows = $this->rankByRisk($perVehicle);

        // Real-ML pass: learn the fleet's normal behaviour and surface outliers.
        $detection = $this->anomaly->detect($perVehicle);

        $kpis = [
            'active_vehicles' => $this->kpi($summary['active_vehicles'], $prevSummary['active_vehicles'], hasPrev: $hasPrev),
            'distance_km'     => $this->kpi($summary['distance_km'], $prevSummary['distance_km'], hasPrev: $hasPrev),
            'safety_events'   => $this->kpi($summary['safety_events'], $prevSummary['safety_events'], invert: true, hasPrev: $hasPrev),
            'idle_min'        => $this->kpi($summary['idle_min'], $prevSummary['idle_min'], invert: true, hasPrev: $hasPrev),
            // Operating efficiency = driving share of engine-on time (higher = better).
            'efficiency_pct'  => $this->kpi($summary['driving_pct'], $prevSummary['driving_pct'], hasPrev: $hasPrev),
        ];

        return [
            'period'       => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'health'       => $health,
            'summary_line' => $this->summaryLine($health, $summary, $totalVehicles, count($unused), $hasPrev),
            'kpis'         => $kpis,
            // ML-detected anomalies lead the panel, then the rule-based findings.
            'insights'     => array_merge(
                $this->anomalyInsights($detection),
                $this->narrative($summary, $prevSummary, $idleRanking, $zones, $unused, $riskRows, $efficiency, $totalVehicles, $hasPrev),
            ),
            'sections'     => [
                'safety'       => $riskRows,
                'efficiency'   => $efficiency,
                'utilization'  => [
                    'total'      => $totalVehicles,
                    'used'       => $summary['active_vehicles'],
                    'unused'     => count($unused),
                    'unused_list'=> array_slice($unused, 0, 15),
                    'top_used'   => collect($perVehicle)->sortByDesc('distance_km')->take(5)->values()->all(),
                ],
                'zones'        => $zones,
                'idle_ranking' => $idleRanking,
                'anomalies'    => $detection,
            ],
            'totals' => $summary,
        ];
    }

    /* ── Scoring ─────────────────────────────────────────────────────────── */

    /**
     * Composite 0–100 fleet-health score from three explainable components.
     */
    public function healthScore(array $summary, int $totalVehicles): array
    {
        $w = $this->weights();

        // Safety: penalise safety events per 100 km driven.
        $per100 = $summary['distance_km'] > 0
            ? $summary['safety_events'] / ($summary['distance_km'] / 100)
            : 0;
        $safety = $this->clamp(100 - $per100 * 10);

        // Utilisation: share of the fleet that actually moved.
        $utilisation = $totalVehicles > 0
            ? $this->clamp($summary['active_vehicles'] / $totalVehicles * 100)
            : 0;

        // Movement efficiency: driving time vs. total engine-on time.
        $engineOn = $summary['driving_min'] + $summary['idle_min'];
        $movement = $engineOn > 0
            ? $this->clamp($summary['driving_min'] / $engineOn * 100)
            : 0;

        $score = (int) round(
            $safety * $w['safety'] + $utilisation * $w['utilization'] + $movement * $w['movement']
        );

        return [
            'score'      => $score,
            'label'      => $this->healthLabel($score),
            'trend'      => 0, // filled in by caller
            'components' => [
                'safety'      => (int) round($safety),
                'utilization' => (int) round($utilisation),
                'movement'    => (int) round($movement),
            ],
        ];
    }

    /**
     * Per-vehicle risk score (0–100, higher = safer). Currently driven by
     * over-speed events + idle ratio (no driver/harsh-braking data yet).
     *
     * @return array<int,array<string,mixed>>
     */
    public function rankByRisk(array $perVehicle): array
    {
        $w1 = (float) Setting::get('risk_weight_overspeed', 8);
        $w2 = (float) Setting::get('risk_weight_idle', 40);

        return collect($perVehicle)
            ->filter(fn ($v) => $v['used'])
            ->map(function ($v) use ($w1, $w2) {
                $per100    = $v['distance_km'] > 0 ? $v['over_speed'] / ($v['distance_km'] / 100) : 0;
                $driveMin  = max(1, $v['idle_min']); // avoid /0; idle-only vehicles score low anyway
                $idleRatio = $v['idle_min'] / ($v['idle_min'] + max(1, $v['distance_km'] * 2));
                $score     = $this->clamp(100 - $per100 * $w1 - $idleRatio * $w2);

                return array_merge($v, [
                    'risk_score' => (int) round($score),
                    'risk_band'  => $this->band($score),
                ]);
            })
            ->sortBy('risk_score')
            ->values()
            ->all();
    }

    /* ── Narrative (the "AI Insights") ───────────────────────────────────── */

    private function summaryLine(array $health, array $summary, int $total, int $unused, bool $hasPrev): string
    {
        $dir = ! $hasPrev ? 'first tracked period'
            : ($health['trend'] > 0 ? 'up ' . $health['trend'] . ' pts'
            : ($health['trend'] < 0 ? 'down ' . abs($health['trend']) . ' pts' : 'unchanged'));

        $tail = match (true) {
            $health['score'] >= 80 => 'Your fleet is running well.',
            $health['score'] >= 60 && $unused > 0 => "But $unused vehicle" . ($unused === 1 ? '' : 's') . ' went unused.',
            $health['score'] >= 60 => 'A few areas need attention.',
            default => 'Several areas need your attention.',
        };

        return "Your fleet scored {$health['score']}/100 this period ({$dir}). {$tail}";
    }

    /**
     * Builds the friendly finding list shown in the "AI Insights" panel.
     * Each item: level (good|warn|info), icon, title, recommendation.
     */
    private function narrative(
        array $summary,
        array $prevSummary,
        array $idleRanking,
        array $zones,
        array $unused,
        array $riskRows,
        array $efficiency,
        int $totalVehicles,
        bool $hasPrev,
    ): array {
        $out = [];

        // Operating efficiency — the headline the client already tracks.
        $fleetEff = $efficiency['fleet'];
        if ($fleetEff['engine_min'] > 0) {
            $idlePct = $fleetEff['idle_pct'];
            $worst   = collect($efficiency['categories'])
                ->where('engine_min', '>', 0)
                ->sortByDesc('idle_pct')
                ->first();

            if ($idlePct >= 40) {
                $line = "The fleet spent {$idlePct}% of engine-on time idling — driving efficiency is only {$fleetEff['driving_pct']}%.";
                $rec  = $worst
                    ? "\"{$worst['category']}\" is the worst at {$worst['idle_pct']}% idle — start there to cut fuel burn."
                    : 'High idle burns fuel and engine hours with no output — target the worst units below.';
                $out[] = ['level' => 'warn', 'icon' => 'alert', 'title' => $line, 'recommendation' => $rec];
            } else {
                $out[] = [
                    'level'          => 'good',
                    'icon'           => 'check',
                    'title'          => "Operating efficiency is {$fleetEff['driving_pct']}% driving vs {$idlePct}% idle — healthy.",
                    'recommendation' => 'Keep idle low; it directly protects fuel spend and engine life.',
                ];
            }
        }

        // Worst idler
        if (! empty($idleRanking)) {
            $top   = $idleRanking[0];
            $hrs   = round($top['idle_min'] / 60, 1);
            $out[] = [
                'level'          => 'warn',
                'icon'           => 'alert',
                'title'          => "{$top['label']} idled the most — {$hrs} hrs of engine-on idle this period.",
                'recommendation' => 'Review this vehicle’s stops; cutting idle saves engine wear and cost.',
            ];
        }

        // Busiest zone
        if (! empty($zones) && $zones[0]['trips'] > 0) {
            $z     = $zones[0];
            $out[] = [
                'level'          => 'info',
                'icon'           => 'zone',
                'title'          => "{$z['zone_name']} was your busiest area — {$z['share_pct']}% of all trips.",
                'recommendation' => 'Make sure resourcing matches where the work actually happens.',
            ];
        }

        // Unused vehicles
        $unusedCount = count($unused);
        if ($unusedCount > 0) {
            $out[] = [
                'level'          => 'warn',
                'icon'           => 'car',
                'title'          => "{$unusedCount} vehicle" . ($unusedCount === 1 ? '' : 's') . ' had no trips this period.',
                'recommendation' => 'Check whether these are idle assets you could redeploy or downsize.',
            ];
        }

        // Riskiest vehicle
        $high = collect($riskRows)->firstWhere('risk_band', 'High');
        if ($high) {
            $out[] = [
                'level'          => 'warn',
                'icon'           => 'shield',
                'title'          => "{$high['label']} has a high risk score ({$high['risk_score']}/100).",
                'recommendation' => 'Flag for a driving review — over-speeding and idling are dragging it down.',
            ];
        }

        // Safety trend — only when there is a real prior baseline to compare to.
        $delta = $summary['safety_events'] - $prevSummary['safety_events'];
        if ($summary['safety_events'] === 0) {
            $out[] = [
                'level'          => 'good',
                'icon'           => 'check',
                'title'          => 'No safety events this period — clean run.',
                'recommendation' => 'Keep it up; consistency here lowers accident and liability risk.',
            ];
        } elseif ($hasPrev && $delta < 0) {
            $out[] = [
                'level'          => 'good',
                'icon'           => 'check',
                'title'          => abs($delta) . ' fewer safety events than last period.',
                'recommendation' => 'Whatever changed is working — keep reinforcing it.',
            ];
        } elseif ($hasPrev && $delta > 0) {
            $out[] = [
                'level'          => 'warn',
                'icon'           => 'alert',
                'title'          => "$delta more safety events than last period.",
                'recommendation' => 'Look at the top offenders below before it becomes a trend.',
            ];
        } elseif (! $hasPrev && $summary['safety_events'] > 0) {
            $out[] = [
                'level'          => 'info',
                'icon'           => 'shield',
                'title'          => $summary['safety_events'] . ' safety events recorded this period.',
                'recommendation' => 'Review the top offenders below; this becomes your baseline.',
            ];
        }

        return $out;
    }

    /**
     * Template layer for the ML findings — turns FleetAnomalyService's structured
     * anomaly data into the same friendly insight items the narrative uses.
     * The model detects; these templates write the words ($0, on-server).
     *
     * @param  array<string,mixed>  $detection
     * @return array<int,array<string,string>>
     */
    private function anomalyInsights(array $detection): array
    {
        if (! ($detection['available'] ?? false) || empty($detection['anomalies'])) {
            return [];
        }

        $n   = count($detection['anomalies']);
        $out = [[
            'level'          => 'info',
            'icon'           => 'ai',
            'title'          => "AI flagged {$n} vehicle" . ($n === 1 ? '' : 's') . ' behaving unlike the rest of the fleet this period.',
            'recommendation' => 'These stood out to the anomaly model (learned from your own telemetry). The most unusual are below.',
        ]];

        foreach ($detection['anomalies'] as $a) {
            $out[] = $this->anomalyLine($a);
        }

        // One-line behaviour-cluster summary, if the fleet split into groups.
        // Aggregate by profile name so the line never lists the same label twice.
        $byProfile = [];
        foreach ($detection['clusters'] ?? [] as $c) {
            $byProfile[$c['profile']] = ($byProfile[$c['profile']] ?? 0) + $c['count'];
        }
        arsort($byProfile);
        if (count($byProfile) > 1) {
            $parts = [];
            foreach (array_slice($byProfile, 0, 3, true) as $profile => $count) {
                $parts[] = "{$count} {$profile}";
            }
            $out[] = [
                'level'          => 'info',
                'icon'           => 'ai',
                'title'          => 'Behaviour groups this period: ' . implode(', ', $parts) . '.',
                'recommendation' => 'The model clustered vehicles by how they actually operate — handy for spotting a unit drifting out of its group.',
            ];
        }

        return $out;
    }

    /**
     * One templated finding for a single anomalous vehicle.
     *
     * @param  array<string,mixed>  $a
     * @return array<string,string>
     */
    private function anomalyLine(array $a): array
    {
        $label  = $a['label'];
        $val    = $a['value'];
        $median = $a['fleet_median'];

        [$icon, $recommendation, $title] = match (true) {
            $a['feature'] === 'idle_pct' && $a['direction'] === 'high' => [
                'alert',
                'Check for a vehicle left running, stuck waiting, or a job that stalled — cutting this saves fuel and engine hours.',
                "{$label} is idling far more than its peers — {$val}% of engine time vs a fleet norm of {$median}%.",
            ],
            $a['feature'] === 'distance_km' && $a['direction'] === 'low' => [
                'car',
                'Possible stranded asset, breakdown, or a unit sitting with the engine on — worth a physical check.',
                "{$label} is barely moving — {$val} km vs a fleet norm around {$median} km — despite engine-on time.",
            ],
            $a['feature'] === 'distance_km' && $a['direction'] === 'high' => [
                'car',
                'Confirm it is assigned appropriately and not being over-used relative to the rest of the fleet.',
                "{$label} is covering far more ground than its peers — {$val} km vs a fleet norm of {$median} km.",
            ],
            in_array($a['feature'], ['over_speed', 'max_speed'], true) => [
                'shield',
                'Flag this vehicle for a driving review — its speeding pattern is out of line with the fleet.',
                "{$label} shows an unusual speeding pattern versus its peers ({$a['feature_name']}: {$val} vs {$median}).",
            ],
            default => [
                'alert',
                'The model flagged this as an outlier — its ' . $a['feature_name'] . ' stands out from the fleet. Worth a look.',
                "{$label} is behaving unlike the rest of the fleet — {$a['feature_name']} is {$a['direction']} ({$val} vs a norm of {$median}).",
            ],
        };

        return [
            'level'          => 'warn',
            'icon'           => $icon,
            'title'          => $title,
            'recommendation' => $recommendation,
        ];
    }

    /* ── Helpers ─────────────────────────────────────────────────────────── */

    private function kpi($current, $previous, bool $invert = false, bool $hasPrev = true): array
    {
        $delta = round($current - $previous, 1);
        $pct   = $previous != 0 ? round(($current - $previous) / abs($previous) * 100) : null;

        // For "bad" metrics (safety events, idle) a decrease is good.
        $good = $invert ? $delta <= 0 : $delta >= 0;

        return [
            'value'      => $current,
            'previous'   => $hasPrev ? $previous : null,
            'delta'      => $hasPrev ? $delta : null,
            'delta_pct'  => $hasPrev ? $pct : null,
            'good'       => $good,
            'comparable' => $hasPrev,
        ];
    }

    private function band(float $score): string
    {
        return $score >= 80 ? 'Low' : ($score >= 60 ? 'Medium' : 'High');
    }

    private function healthLabel(float $score): string
    {
        return match (true) {
            $score >= 85 => 'Excellent',
            $score >= 70 => 'Good',
            $score >= 55 => 'Fair',
            default      => 'Needs attention',
        };
    }

    private function clamp(float $n, float $min = 0, float $max = 100): float
    {
        return max($min, min($max, $n));
    }
}
