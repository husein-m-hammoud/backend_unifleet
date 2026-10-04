<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use App\Services\HeatmapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AnalyticsController extends Controller
{
    public function __construct(
        private AnalyticsService $analytics,
        private HeatmapService $heatmap,
    ) {}

    /**
     * GET /api/analytics/insights?from&to&zone&type
     *
     * The "AI Insights" payload: fleet health score, KPIs vs. previous period,
     * plain-language findings and the detail sections. Always scoped to the
     * caller's own vehicles via vehicleQuery().
     */
    public function insights(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        // Base scope = everything this user may see, then apply page filters.
        $query = $request->user()->vehicleQuery();

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }
        if ($request->filled('zone')) {
            $zoneId = (int) $request->query('zone');
            $query->whereHas('zones', fn ($q) => $q->where('zones.id', $zoneId));
        }

        $vehicleIds     = $query->pluck('id');
        $totalVehicles  = $vehicleIds->count();

        $payload = $this->analytics->insights($vehicleIds, $from, $to, $totalVehicles);

        return response()->json($payload);
    }

    /**
     * GET /api/analytics/heatmap?from&to&zone&type&mode&precision
     *
     * Operational heatmap (contract §3.5): weighted GPS density cells for the
     * caller's own vehicles. `mode=idle` restricts to stationary fixes, which is
     * what makes the map operationally useful rather than just pretty — a depot
     * reads as a hotspot either way, but only the idle view shows wasted hours.
     */
    public function heatmap(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode'      => ['nullable', 'string', 'in:all,idle,moving'],
            'precision' => ['nullable', 'integer', 'min:2', 'max:5'],
            'zone'      => ['nullable', 'integer'],
            'type'      => ['nullable', 'string', 'max:60'],
        ]);

        [$from, $to] = $this->range($request);

        // Same scoping as insights(): a scoped user never sees another zone's
        // movements, so the heatmap cannot leak location data across grants.
        $query = $request->user()->vehicleQuery();

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }
        if ($request->filled('zone')) {
            $query->whereHas('zones', fn ($q) => $q->where('zones.id', (int) $request->query('zone')));
        }

        $payload = $this->heatmap->density(
            $query->pluck('id'),
            $from,
            $to,
            // 3 dp (~110 m) is the default because it covers the whole fleet
            // footprint without hitting the cell limit — a finer grid truncates
            // to the hottest cells and then the returned bounds no longer
            // describe all the data.
            (int) ($data['precision'] ?? 3),
            $data['mode'] ?? 'all',
        );

        $payload['vehicles_in_scope'] = $query->count();

        return response()->json($payload);
    }

    /**
     * Resolve the [from, to] window. Defaults to the last 7 days.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    private function range(Request $request): array
    {
        $to = $request->filled('to')
            ? Carbon::parse($request->query('to'))
            : Carbon::now();

        $from = $request->filled('from')
            ? Carbon::parse($request->query('from'))
            : (clone $to)->subDays(7);

        return [$from, $to];
    }
}
