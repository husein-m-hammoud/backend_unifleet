<?php

namespace App\Services;

use App\Models\Setting;
use Rubix\ML\AnomalyDetectors\IsolationForest;
use Rubix\ML\Clusterers\KMeans;
use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\Transformers\ZScaleStandardizer;
use Throwable;

/**
 * The "real AI" layer — genuine unsupervised machine learning over the fleet's
 * own telemetry. NO external service, NO recurring cost: Rubix ML runs in pure
 * PHP on our own server (PDPL-safe, nothing leaves the box).
 *
 * It answers the question the hand-written rules cannot: "which vehicles are
 * behaving *unlike the rest of the fleet* this period, and why?" — the
 * "detection of unusual operational patterns" the contract asks for.
 *
 * This service returns DATA ONLY (scores, flags, the deviating feature). Turning
 * those findings into readable sentences is the template layer's job
 * (AnalyticsService::anomalyInsights) — the model detects, the templates write.
 *
 * Two algorithms, both learned from the current fleet (no labels needed):
 *   • Isolation Forest — scores how anomalous each vehicle is vs. its peers.
 *   • K-Means          — groups vehicles into behaviour profiles (clusters).
 */
class FleetAnomalyService
{
    /**
     * Behaviour features fed to the models, in fixed order. Each is a plain
     * number already computed by FleetMetricsService::perVehicle — so the ML
     * never touches raw GPS and can never disagree with the report's figures.
     *
     * @var array<string,string>  feature key => human label (for the "why")
     */
    private const FEATURES = [
        'idle_pct'    => 'idle',
        'distance_km' => 'distance',
        'trips'       => 'trip count',
        'engine_min'  => 'engine hours',
        'max_speed'   => 'top speed',
        'over_speed'  => 'over-speed events',
    ];

    /**
     * Detect behavioural anomalies across an already-computed per-vehicle set.
     *
     * @param  array<int,array<string,mixed>>  $perVehicle  rows from FleetMetricsService::perVehicle
     * @return array{
     *   available: bool,
     *   sample_size: int,
     *   anomalies: array<int,array<string,mixed>>,
     *   clusters: array<int,array<string,mixed>>
     * }
     */
    public function detect(array $perVehicle): array
    {
        // Only vehicles that actually ran can have a "behaviour" to judge.
        $active = array_values(array_filter($perVehicle, fn ($v) => $v['used'] ?? false));

        $minVehicles = (int) Setting::get('anomaly_min_vehicles', 8);
        if (count($active) < $minVehicles) {
            // Too few samples for the model to learn a meaningful "normal".
            return ['available' => false, 'sample_size' => count($active), 'anomalies' => [], 'clusters' => []];
        }

        try {
            return $this->run($active);
        } catch (Throwable $e) {
            // Never let the ML break the report — the deterministic narrative
            // still runs. Fail closed to "no anomalies surfaced".
            report($e);

            return ['available' => false, 'sample_size' => count($active), 'anomalies' => [], 'clusters' => []];
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $active
     */
    private function run(array $active): array
    {
        $keys    = array_keys(self::FEATURES);
        $samples = array_map(
            fn ($v) => array_map(fn ($k) => (float) ($v[$k] ?? 0), $keys),
            $active,
        );

        // ── Isolation Forest: score each vehicle's "unusualness" vs. the fleet.
        // Standardise first so no single large-magnitude feature (distance) drowns
        // out the rest.
        $contamination = (float) Setting::get('anomaly_contamination', 0.1);
        $estimators    = (int) Setting::get('anomaly_trees', 120);

        $dataset = Unlabeled::build($samples)->apply(new ZScaleStandardizer());

        $forest = new IsolationForest($estimators, null, $contamination);
        $forest->train($dataset);

        $flags  = $forest->predict($dataset); // 1 = anomaly, 0 = normal
        $scores = $forest->score($dataset);   // higher = more anomalous

        // ── K-Means: assign each vehicle to a behaviour cluster (profile).
        $clusterOf = $this->cluster($samples, count($active));

        // ── Robust per-feature stats (median + MAD) for the "why" — computed on
        // RAW values so the explanation is human-readable, not standardised.
        $stats = [];
        foreach ($keys as $i => $key) {
            $col        = array_column($samples, $i);
            $median     = $this->median($col);
            $mad        = $this->median(array_map(fn ($x) => abs($x - $median), $col));
            $stats[$key] = ['median' => $median, 'mad' => $mad];
        }

        // ── Build one finding per flagged vehicle, worst-first.
        $anomalies = [];
        foreach ($active as $idx => $v) {
            if (($flags[$idx] ?? 0) !== 1) {
                continue;
            }

            [$feature, $z, $direction] = $this->dominantFeature($v, $keys, $stats);

            $anomalies[] = [
                'vehicle_id'   => $v['vehicle_id'],
                'label'        => $v['label'],
                'type'         => $v['type'] ?? null,
                'score'        => round((float) ($scores[$idx] ?? 0), 3),
                'feature'      => $feature,                 // deviating feature key
                'feature_name' => self::FEATURES[$feature], // human label
                'direction'    => $direction,               // 'high' | 'low'
                'value'        => $v[$feature] ?? 0,
                'fleet_median' => round($stats[$feature]['median'], 1),
                'z'            => round($z, 2),
                'cluster'      => $clusterOf[$idx] ?? null,
            ];
        }

        // Sort by anomaly score (most unusual first) and cap the panel.
        usort($anomalies, fn ($a, $b) => $b['score'] <=> $a['score']);
        $anomalies = array_slice($anomalies, 0, (int) Setting::get('anomaly_max_findings', 4));

        return [
            'available'   => true,
            'sample_size' => count($active),
            'anomalies'   => $anomalies,
            'clusters'    => $this->summariseClusters($clusterOf, $active),
        ];
    }

    /**
     * K-Means behaviour clustering. Returns a cluster index per sample (by
     * position). Cluster count scales with fleet size (2–4).
     *
     * @param  array<int,array<int,float>>  $samples
     * @return array<int,int>
     */
    private function cluster(array $samples, int $n): array
    {
        $k = max(2, min(4, intdiv($n, 6)));

        $dataset = Unlabeled::build($samples)->apply(new ZScaleStandardizer());

        $kmeans = new KMeans($k);
        $kmeans->train($dataset);

        return $kmeans->predict($dataset);
    }

    /**
     * Turn cluster assignments into named behaviour profiles with counts.
     * A cluster is named by the feature its members skew highest on.
     *
     * @param  array<int,int>  $clusterOf
     * @param  array<int,array<string,mixed>>  $active
     * @return array<int,array<string,mixed>>
     */
    private function summariseClusters(array $clusterOf, array $active): array
    {
        $groups = [];
        foreach ($clusterOf as $idx => $c) {
            $groups[$c][] = $active[$idx];
        }

        $out = [];
        foreach ($groups as $c => $members) {
            $avgIdle = $this->mean(array_map(fn ($v) => (float) $v['idle_pct'], $members));
            $avgDist = $this->mean(array_map(fn ($v) => (float) $v['distance_km'], $members));

            $out[] = [
                'cluster'     => $c,
                'count'       => count($members),
                'avg_idle_pct'=> round($avgIdle, 1),
                'avg_distance'=> round($avgDist, 1),
                'profile'     => $this->profileName($avgIdle, $avgDist),
            ];
        }

        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    /** Coarse human label for a cluster centroid. */
    private function profileName(float $idlePct, float $distanceKm): string
    {
        // Idle runs high fleet-wide, so distance is the real separator between
        // groups; fall back to idle only when distance doesn't distinguish them.
        return match (true) {
            $distanceKm >= 200 => 'long-haul / high-distance',
            $distanceKm < 30 => 'low-activity',
            $idlePct >= 55 => 'heavy idlers',
            default => 'steady workers',
        };
    }

    /**
     * Which feature makes this vehicle an outlier? The one with the largest
     * robust z-score vs. the fleet. Returns [featureKey, z, direction].
     *
     * @param  array<string,mixed>  $v
     * @param  array<int,string>    $keys
     * @param  array<string,array{median:float,mad:float}>  $stats
     * @return array{0:string,1:float,2:string}
     */
    private function dominantFeature(array $v, array $keys, array $stats): array
    {
        $best = [$keys[0], 0.0, 'high'];

        foreach ($keys as $key) {
            $median = $stats[$key]['median'];
            $mad    = $stats[$key]['mad'];
            $scale  = $mad > 0 ? 1.4826 * $mad : max(1.0, abs($median) * 0.1);
            $z      = ((float) ($v[$key] ?? 0) - $median) / $scale;

            if (abs($z) > abs($best[1])) {
                $best = [$key, $z, $z >= 0 ? 'high' : 'low'];
            }
        }

        return $best;
    }

    /** @param array<int,float> $values */
    private function median(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        sort($values);
        $n   = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /** @param array<int,float> $values */
    private function mean(array $values): float
    {
        return empty($values) ? 0.0 : array_sum($values) / count($values);
    }
}
