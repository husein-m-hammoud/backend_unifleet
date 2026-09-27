<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\Zone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared aggregation layer for Reports + AI Insights.
 *
 * The caller passes an ALREADY-SCOPED collection of vehicle ids (from
 * User::vehicleQuery()) so permission scoping lives in one place. Every method
 * returns plain arrays — no HTTP, no auth, no models leaking out.
 *
 * Units in the DB (verified against live data):
 *   trips.distance      = metres            → km  (÷1000)
 *   trips.idle_time     = seconds           → min (÷60)
 *   trips.driving_time  = seconds           → min (÷60)
 *   trips.avg_speed     = km/h
 *   trips.max_speed     = km/h
 *
 * Fuel is intentionally excluded from this version.
 */
class FleetMetricsService
{
    /** Alert types that count as "safety events" for scoring/insights. */
    public const SAFETY_ALERT_TYPES = ['over_speed', 'idle', 'zone_unauthorized', 'geofence_exit'];

    /**
     * Fleet-wide totals for a period.
     *
     * @param  Collection<int>|array<int>  $vehicleIds
     */
    public function summary($vehicleIds, Carbon $from, Carbon $to): array
    {
        $ids = collect($vehicleIds)->all();

        if (empty($ids)) {
            return $this->emptySummary();
        }

        $trips = Trip::whereIn('vehicle_id', $ids)
            ->where('start_time', '>=', $from)
            ->where('start_time', '<=', $to)
            ->selectRaw('
                COUNT(*)                         as trip_count,
                COUNT(DISTINCT vehicle_id)       as active_vehicles,
                COALESCE(SUM(distance), 0)       as distance_m,
                COALESCE(SUM(idle_time), 0)      as idle_s,
                COALESCE(SUM(driving_time), 0)   as driving_s,
                COALESCE(AVG(NULLIF(avg_speed,0)), 0) as avg_speed,
                COALESCE(MAX(max_speed), 0)      as max_speed
            ')
            ->first();

        $alertsByType = Alert::whereIn('vehicle_id', $ids)
            ->where('triggered_at', '>=', $from)
            ->where('triggered_at', '<=', $to)
            ->select('type', DB::raw('COUNT(*) as c'))
            ->groupBy('type')
            ->pluck('c', 'type');

        $safetyEvents = collect(self::SAFETY_ALERT_TYPES)
            ->sum(fn ($t) => (int) ($alertsByType[$t] ?? 0));

        $idleMin    = (int) round(((float) $trips->idle_s) / 60);
        $drivingMin = (int) round(((float) $trips->driving_s) / 60);
        $engineMin  = $idleMin + $drivingMin;

        return [
            'trips'            => (int) $trips->trip_count,
            'active_vehicles'  => (int) $trips->active_vehicles,
            'distance_km'      => round(((float) $trips->distance_m) / 1000, 1),
            'idle_min'         => $idleMin,
            'driving_min'      => $drivingMin,
            'engine_min'       => $engineMin,
            'idle_pct'         => $engineMin > 0 ? round($idleMin / $engineMin * 100, 1) : 0.0,
            'driving_pct'      => $engineMin > 0 ? round($drivingMin / $engineMin * 100, 1) : 0.0,
            'avg_speed'        => round((float) $trips->avg_speed, 1),
            'max_speed'        => round((float) $trips->max_speed, 1),
            'safety_events'    => (int) $safetyEvents,
            'alerts_by_type'   => $alertsByType->map(fn ($c) => (int) $c)->toArray(),
        ];
    }

    /**
     * One row per vehicle. Includes vehicles with ZERO trips in range so the
     * "unused" flag is meaningful.
     *
     * @param  Collection<int>|array<int>  $vehicleIds
     * @return array<int,array<string,mixed>>
     */
    public function perVehicle($vehicleIds, Carbon $from, Carbon $to): array
    {
        $ids = collect($vehicleIds)->all();
        if (empty($ids)) {
            return [];
        }

        $trips = Trip::whereIn('vehicle_id', $ids)
            ->where('start_time', '>=', $from)
            ->where('start_time', '<=', $to)
            ->select('vehicle_id', DB::raw('
                COUNT(*)                       as trip_count,
                COALESCE(SUM(distance), 0)     as distance_m,
                COALESCE(SUM(idle_time), 0)    as idle_s,
                COALESCE(SUM(driving_time), 0) as driving_s,
                COALESCE(MAX(max_speed), 0)    as max_speed
            '))
            ->groupBy('vehicle_id')
            ->get()
            ->keyBy('vehicle_id');

        $overSpeed = Alert::whereIn('vehicle_id', $ids)
            ->where('type', 'over_speed')
            ->where('triggered_at', '>=', $from)
            ->where('triggered_at', '<=', $to)
            ->select('vehicle_id', DB::raw('COUNT(*) as c'))
            ->groupBy('vehicle_id')
            ->pluck('c', 'vehicle_id');

        $vehicles = Vehicle::whereIn('id', $ids)
            ->get(['id', 'plate_no', 'type', 'vehicle_make', 'vehicle_model']);

        return $vehicles->map(function (Vehicle $v) use ($trips, $overSpeed) {
            $t          = $trips->get($v->id);
            $tripCount  = $t ? (int) $t->trip_count : 0;
            $distanceKm = $t ? round(((float) $t->distance_m) / 1000, 1) : 0.0;
            $idleMin    = $t ? (int) round(((float) $t->idle_s) / 60) : 0;
            $drivingMin = $t ? (int) round(((float) $t->driving_s) / 60) : 0;
            $engineMin  = $idleMin + $drivingMin;

            return [
                'vehicle_id'   => $v->id,
                'label'        => $v->plate_no ?: ('Vehicle #' . $v->id),
                'type'         => $v->type,
                'category'     => $this->categoryOf($v->type),
                'trips'        => $tripCount,
                'distance_km'  => $distanceKm,
                'idle_min'     => $idleMin,
                'driving_min'  => $drivingMin,
                'engine_min'   => $engineMin,
                'idle_pct'     => $engineMin > 0 ? round($idleMin / $engineMin * 100, 1) : 0.0,
                'driving_pct'  => $engineMin > 0 ? round($drivingMin / $engineMin * 100, 1) : 0.0,
                'max_speed'    => $t ? round((float) $t->max_speed, 1) : 0.0,
                'over_speed'   => (int) ($overSpeed[$v->id] ?? 0),
                'used'         => $tripCount > 0,
            ];
        })->all();
    }

    /**
     * Trips + distance grouped by the zones each vehicle is assigned to
     * (vehicle_zone pivot). A vehicle in multiple zones contributes to each.
     *
     * @param  Collection<int>|array<int>  $vehicleIds
     * @return array<int,array<string,mixed>>
     */
    public function zoneActivity($vehicleIds, Carbon $from, Carbon $to): array
    {
        $ids = collect($vehicleIds)->all();
        if (empty($ids)) {
            return [];
        }

        $rows = DB::table('vehicle_zone as vz')
            ->join('zones as z', 'z.id', '=', 'vz.zone_id')
            ->leftJoin('trips as t', function ($join) use ($from, $to) {
                $join->on('t.vehicle_id', '=', 'vz.vehicle_id')
                    ->where('t.start_time', '>=', $from)
                    ->where('t.start_time', '<=', $to);
            })
            ->whereIn('vz.vehicle_id', $ids)
            ->groupBy('z.id', 'z.name')
            ->select('z.id as zone_id', 'z.name as zone_name', DB::raw('
                COUNT(t.id)                      as trip_count,
                COALESCE(SUM(t.distance), 0)     as distance_m,
                COUNT(DISTINCT vz.vehicle_id)    as vehicles
            '))
            ->orderByDesc('trip_count')
            ->get();

        $totalTrips = (int) $rows->sum('trip_count');

        return $rows->map(fn ($r) => [
            'zone_id'     => (int) $r->zone_id,
            'zone_name'   => $r->zone_name,
            'trips'       => (int) $r->trip_count,
            'distance_km' => round(((float) $r->distance_m) / 1000, 1),
            'vehicles'    => (int) $r->vehicles,
            'share_pct'   => $totalTrips > 0 ? round(((int) $r->trip_count) / $totalTrips * 100) : 0,
        ])->all();
    }

    /**
     * Vehicles sorted by idle minutes (worst first), limited to top N with idle > 0.
     */
    public function idleRanking($vehicleIds, Carbon $from, Carbon $to, int $limit = 10): array
    {
        return collect($this->perVehicle($vehicleIds, $from, $to))
            ->filter(fn ($v) => $v['idle_min'] > 0)
            ->sortByDesc('idle_min')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Vehicles with zero trips in the range (possible idle assets).
     */
    public function unusedVehicles($vehicleIds, Carbon $from, Carbon $to): array
    {
        return collect($this->perVehicle($vehicleIds, $from, $to))
            ->filter(fn ($v) => ! $v['used'])
            ->values()
            ->all();
    }

    private function emptySummary(): array
    {
        return [
            'trips'           => 0,
            'active_vehicles' => 0,
            'distance_km'     => 0.0,
            'idle_min'        => 0,
            'driving_min'     => 0,
            'engine_min'      => 0,
            'idle_pct'        => 0.0,
            'driving_pct'     => 0.0,
            'avg_speed'       => 0.0,
            'max_speed'       => 0.0,
            'safety_events'   => 0,
            'alerts_by_type'  => [],
        ];
    }

    /**
     * Operating-efficiency breakdown by category (vehicle type), the way the
     * client already grades the fleet: engine time split into driving vs idle,
     * with a "weighted idle %" per category. Every metric here comes from the
     * same per-vehicle rows the rest of the report uses, so nothing can disagree.
     *
     * Returns:
     *   fleet:      weighted totals across the scoped set
     *   categories: one row per vehicle type (busiest engine-hours first)
     *
     * @param  Collection<int>|array<int>  $vehicleIds
     */
    public function efficiency($vehicleIds, Carbon $from, Carbon $to): array
    {
        $rows = collect($this->perVehicle($vehicleIds, $from, $to));

        $categories = $rows
            ->groupBy('category')
            ->map(function (Collection $group, string $category) {
                $engineMin  = (int) $group->sum('engine_min');
                $idleMin    = (int) $group->sum('idle_min');
                $drivingMin = (int) $group->sum('driving_min');

                return [
                    'category'    => $category,
                    'units'       => $group->count(),
                    'active'      => $group->where('used', true)->count(),
                    'trips'       => (int) $group->sum('trips'),
                    'distance_km' => round((float) $group->sum('distance_km'), 1),
                    'idle_min'    => $idleMin,
                    'driving_min' => $drivingMin,
                    'engine_min'  => $engineMin,
                    'idle_pct'    => $engineMin > 0 ? round($idleMin / $engineMin * 100, 1) : 0.0,
                    'driving_pct' => $engineMin > 0 ? round($drivingMin / $engineMin * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('engine_min')
            ->values()
            ->all();

        $engineMin  = (int) $rows->sum('engine_min');
        $idleMin    = (int) $rows->sum('idle_min');
        $drivingMin = (int) $rows->sum('driving_min');

        return [
            'fleet' => [
                'units'       => $rows->count(),
                'active'      => $rows->where('used', true)->count(),
                'trips'       => (int) $rows->sum('trips'),
                'distance_km' => round((float) $rows->sum('distance_km'), 1),
                'idle_min'    => $idleMin,
                'driving_min' => $drivingMin,
                'engine_min'  => $engineMin,
                'idle_pct'    => $engineMin > 0 ? round($idleMin / $engineMin * 100, 1) : 0.0,
                'driving_pct' => $engineMin > 0 ? round($drivingMin / $engineMin * 100, 1) : 0.0,
            ],
            'categories'  => $categories,
            // Per-unit split ordered worst-idler-first, only units that ran.
            'per_vehicle' => $rows
                ->where('used', true)
                ->sortByDesc('idle_pct')
                ->values()
                ->all(),
        ];
    }

    /** Normalise a raw vehicle type into a display category. */
    private function categoryOf(?string $type): string
    {
        if ($type === null || $type === '' || strtoupper($type) === 'UNKNOWN') {
            return 'Uncategorized';
        }

        // "DUMP_TRUCK" → "Dump Truck"
        return ucwords(strtolower(str_replace('_', ' ', $type)));
    }
}
