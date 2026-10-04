<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Operational heatmap (contract §3.5) — where the fleet actually spends its time.
 *
 * Built from stored GPS fixes in `vehicle_positions`, NOT from geofences. The
 * contract wording says "heatmaps generated using Safee geofence data", but a
 * geofence is a drawn boundary, not a density measurement — it can tell you
 * where someone *expected* activity, never where activity happened. Density has
 * to come from the fixes, and there are ~58k of them already stored.
 *
 * The aggregation happens in SQL, not PHP: lat/lon are rounded to a grid and
 * counted, so a quarter-million fixes collapse to a few thousand weighted cells.
 * Sending raw points instead would mean a multi-megabyte payload that the
 * browser then has to bucket anyway. Uses the existing
 * (vehicle_id, time) index.
 */
class HeatmapService
{
    /** Grid precision in decimal places → approximate cell size at this latitude. */
    public const PRECISION_METRES = [
        2 => '~1.1 km',
        3 => '~110 m',
        4 => '~11 m',
        5 => '~1 m',
    ];

    /**
     * A vehicle reporting 0 km/h is parked or idling. Deliberately a small
     * window rather than exactly 0: GPS jitter puts a stationary vehicle at
     * 0.3–1.5 km/h, which would otherwise read as "moving".
     */
    public const IDLE_SPEED_MAX = 2.0;

    /**
     * Weighted density cells for a set of vehicles over a time window.
     *
     * @param  Collection<int,int>  $vehicleIds  Already scoped by the caller.
     * @param  string  $mode  all | idle | moving
     * @return array<string,mixed>
     */
    public function density(
        Collection $vehicleIds,
        Carbon $from,
        Carbon $to,
        int $precision = 3,
        string $mode = 'all',
        int $limit = 5000,
    ): array {
        // Clamp rather than trust: precision is interpolated into the SQL below
        // (round()'s second argument cannot be a bound parameter in Postgres),
        // so it must be a known-safe integer.
        $precision = max(2, min(5, $precision));

        if ($vehicleIds->isEmpty()) {
            return $this->empty($precision, $mode, $from, $to);
        }

        $query = DB::table('vehicle_positions')
            ->whereIn('vehicle_id', $vehicleIds)
            // Safee returns lat=0,lon=0 for offline/parked units and older rows
            // still hold them; (0,0) is in the Gulf of Guinea and would put a
            // hotspot off the coast of Africa.
            ->where('lat', '!=', 0)
            ->whereBetween('time', [$from, $to]);

        $this->applyMode($query, $mode);

        $cells = (clone $query)
            ->selectRaw("round(lat::numeric, {$precision}) AS glat")
            ->selectRaw("round(lon::numeric, {$precision}) AS glon")
            ->selectRaw('count(*) AS c')
            ->groupBy('glat', 'glon')
            // Heaviest first, so a LIMIT keeps the hotspots and drops the
            // long tail of single-fix cells — the opposite would be useless.
            ->orderByDesc('c')
            ->limit($limit)
            ->get();

        $points = $cells->map(fn ($r) => [
            'lat'    => (float) $r->glat,
            'lon'    => (float) $r->glon,
            'weight' => (int) $r->c,
        ])->all();

        $weights = array_column($points, 'weight');

        return [
            'points'          => $points,
            'cell_count'      => count($points),
            'max_weight'      => $weights === [] ? 0 : max($weights),
            'total_fixes'     => (int) array_sum($weights),
            // Total before the LIMIT, so the UI can say "showing the top N".
            'cells_available' => (clone $query)
                ->distinct()
                ->selectRaw("round(lat::numeric, {$precision}) AS glat, round(lon::numeric, {$precision}) AS glon")
                ->get()
                ->count(),
            'bounds'          => $this->bounds($points),
            'precision'       => $precision,
            'cell_size'       => self::PRECISION_METRES[$precision] ?? null,
            'mode'            => $mode,
            'from'            => $from->toIso8601String(),
            'to'              => $to->toIso8601String(),
        ];
    }

    /**
     * Restrict to idle or moving fixes. `idle` answers the question the client
     * actually cares about — where vehicles sit burning hours — which plain
     * density cannot, because a depot looks busy either way.
     */
    private function applyMode($query, string $mode): void
    {
        match ($mode) {
            'idle'   => $query->where('speed', '<=', self::IDLE_SPEED_MAX)->whereNotNull('speed'),
            'moving' => $query->where('speed', '>', self::IDLE_SPEED_MAX),
            default  => null,   // all
        };
    }

    /** Map-fitting bounds for the returned cells. */
    private function bounds(array $points): ?array
    {
        if ($points === []) {
            return null;
        }

        $lats = array_column($points, 'lat');
        $lons = array_column($points, 'lon');

        return [
            'north' => max($lats),
            'south' => min($lats),
            'east'  => max($lons),
            'west'  => min($lons),
        ];
    }

    private function empty(int $precision, string $mode, Carbon $from, Carbon $to): array
    {
        return [
            'points'          => [],
            'cell_count'      => 0,
            'max_weight'      => 0,
            'total_fixes'     => 0,
            'cells_available' => 0,
            'bounds'          => null,
            'precision'       => $precision,
            'cell_size'       => self::PRECISION_METRES[$precision] ?? null,
            'mode'            => $mode,
            'from'            => $from->toIso8601String(),
            'to'              => $to->toIso8601String(),
        ];
    }
}
